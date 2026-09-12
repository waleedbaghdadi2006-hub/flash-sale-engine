<?php

namespace App\Http\Controllers;

use App\Http\Requests\FlashSale\AttachFlashSaleItemRequest;
use App\Http\Requests\FlashSale\PurchaseFlashSaleItemRequest;
use App\Http\Requests\FlashSale\StoreFlashSaleRequest;
use App\Http\Requests\FlashSale\UpdateFlashSaleRequest;
use App\Jobs\ProcessFlashSalePurchase;
use App\Models\FlashSale;
use App\Models\FlashSaleItem;
use App\Services\FlashSaleStock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FlashSaleController extends Controller
{
    /**
     * List flash sales. Supports ?status=active filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $flashSales = FlashSale::query()
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->withCount('items')
            ->orderByDesc('starts_at')
            ->paginate(20);

        return response()->json($flashSales);
    }

    public function store(StoreFlashSaleRequest $request): JsonResponse
    {
        $flashSale = DB::transaction(function () use ($request) {
            $flashSale = FlashSale::create($request->safe()->only([
                'title', 'description', 'starts_at', 'ends_at', 'status',
            ]));

            foreach ($request->safe()->input('items', []) as $item) {
                $flashSale->items()->create($item);
            }

            return $flashSale;
        });

        return response()->json($flashSale->load('items'), 201);
    }

    public function show(FlashSale $flashSale): JsonResponse
    {
        return response()->json($flashSale->load('items.product'));
    }

    public function update(UpdateFlashSaleRequest $request, FlashSale $flashSale): JsonResponse
    {
        $flashSale->update($request->validated());

        return response()->json($flashSale);
    }

    public function destroy(FlashSale $flashSale): JsonResponse
    {
        $flashSale->delete();

        return response()->json(null, 204);
    }

    /**
     * Add a product to a flash sale with its sale price and quantity cap.
     */
    public function addItem(AttachFlashSaleItemRequest $request, FlashSale $flashSale): JsonResponse
    {
        $item = $flashSale->items()->create($request->validated());

        return response()->json($item->load('product'), 201);
    }

    public function removeItem(FlashSale $flashSale, FlashSaleItem $item): JsonResponse
    {
        abort_unless($item->flash_sale_id === $flashSale->id, 404);

        $item->delete();

        return response()->json(null, 204);
    }

    /**
     * Attempt to purchase a flash sale item.
     *
     * This does NOT reserve stock synchronously in the database — under
     * flash-sale traffic, doing the optimistic-lock retry loop inline would
     * mean many requests fighting over the same row at once. Instead we
     * hand the reservation + order creation off to a queued job (serialized
     * by the queue worker pool) and return a reference the client can poll
     * for the outcome.
     *
     * Before dispatching, a Redis atomic counter (FlashSaleStock) acts as a
     * fast gatekeeper: it rejects sold-out requests in microseconds without
     * touching MySQL at all, and only requests that win the Redis
     * decrement get queued. That counter is a cache in front of the
     * DB-authoritative reservation — PurchaseService still re-checks and
     * re-reserves against the `flash_sale_items` row inside the job, which
     * remains the permanent source of truth. If Redis is disabled
     * (`flash_sale.redis_stock_enabled`) or unavailable, this falls back to
     * the original cheap-check-then-queue behavior.
     *
     * Route for this action must be behind the `flash_sale.active` middleware.
     */
    public function purchase(PurchaseFlashSaleItemRequest $request, FlashSale $flashSale, FlashSaleStock $flashSaleStock): JsonResponse
    {
        $flashSaleItem = FlashSaleItem::query()
            ->where('flash_sale_id', $flashSale->id)
            ->where('product_id', $request->validated('product_id'))
            ->firstOrFail();

        $quantity = (int) $request->validated('quantity');

        // Did the Redis counter itself authoritatively decrement stock for
        // this request? Only true on a genuine RESERVED result — never on
        // SOLD_OUT (we return before reaching here) or UNAVAILABLE (Redis
        // is disabled/down, so nothing was reserved and the job must rely
        // solely on the DB lock). The job needs to know this so it can
        // release the unit back to Redis if the DB-side reservation fails.
        $reservedViaRedis = false;

        if (config('flash_sale.redis_stock_enabled')) {
            $reservation = $flashSaleStock->reserve($flashSaleItem, $quantity);

            if ($reservation === FlashSaleStock::SOLD_OUT) {
                return response()->json([
                    'message' => 'This item is sold out.',
                ], 409);
            }

            $reservedViaRedis = $reservation === FlashSaleStock::RESERVED;
        }

        // Cheap, non-authoritative "obviously sold out" check. Only needed
        // when Redis didn't already give us an authoritative answer above
        // (disabled or UNAVAILABLE) — the job re-checks authoritatively
        // against the DB before reserving anything either way.
        if (!$reservedViaRedis && $flashSaleItem->remainingStock() < $quantity) {
            return response()->json([
                'message' => 'This item is sold out.',
            ], 409);
        }

        $referenceId = (string) Str::uuid();

        Cache::put("flash_sale_purchase:{$referenceId}", [
            'user_id' => $request->user()->id,
            'status' => 'pending',
        ], now()->addMinutes(15));

        ProcessFlashSalePurchase::dispatch(
            referenceId: $referenceId,
            userId: $request->user()->id,
            flashSaleItemId: $flashSaleItem->id,
            quantity: $quantity,
            shippingAddressId: (int) $request->validated('shipping_address_id'),
            billingAddressId: $request->validated('billing_address_id'),
            reservedViaRedis: $reservedViaRedis,
        );

        return response()->json([
            'message' => 'Your purchase is being processed.',
            'reference_id' => $referenceId,
            'status_url' => route('flash-sales.purchases.status', ['reference' => $referenceId]),
        ], 202);
    }

    /**
     * Poll the outcome of a queued purchase attempt.
     */
    public function purchaseStatus(Request $request, string $reference): JsonResponse
    {
        $status = Cache::get("flash_sale_purchase:{$reference}");

        abort_unless($status !== null, 404, 'Unknown or expired purchase reference.');
        abort_unless((int) ($status['user_id'] ?? 0) === (int) $request->user()->id, 404, 'Unknown or expired purchase reference.');

        unset($status['user_id']);

        return response()->json($status);
    }
}
