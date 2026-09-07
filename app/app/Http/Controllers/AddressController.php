<?php

namespace App\Http\Controllers;

use App\Http\Requests\Address\StoreAddressRequest;
use App\Http\Requests\Address\UpdateAddressRequest;
use App\Models\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CRUD for the authenticated user's own addresses. Every action here is
 * scoped to $request->user() — this is the only source of shipping/billing
 * addresses that StoreOrderRequest / BuyNowRequest validate against, so
 * without it checkout has no addresses to choose from.
 */
class AddressController extends Controller
{
    /**
     * GET /addresses
     */
    public function index(Request $request): JsonResponse
    {
        $addresses = Address::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($addresses);
    }

    /**
     * POST /addresses
     */
    public function store(StoreAddressRequest $request): JsonResponse
    {
        $data = $request->validated();
        $userId = $request->user()->id;

        $address = DB::transaction(function () use ($data, $userId) {
            if (!empty($data['is_default_shipping'])) {
                Address::where('user_id', $userId)->update(['is_default_shipping' => false]);
            }
            if (!empty($data['is_default_billing'])) {
                Address::where('user_id', $userId)->update(['is_default_billing' => false]);
            }

            return Address::create(array_merge($data, ['user_id' => $userId]));
        });

        return response()->json($address, 201);
    }

    /**
     * PUT/PATCH /addresses/{id}
     */
    public function update(UpdateAddressRequest $request, int $id): JsonResponse
    {
        $userId = $request->user()->id;

        $address = Address::where('id', $id)->where('user_id', $userId)->first();

        if (!$address) {
            return response()->json(['message' => 'Address not found.'], 404);
        }

        $data = $request->validated();

        DB::transaction(function () use ($data, $userId, $address) {
            if (!empty($data['is_default_shipping'])) {
                Address::where('user_id', $userId)->where('id', '!=', $address->id)
                    ->update(['is_default_shipping' => false]);
            }
            if (!empty($data['is_default_billing'])) {
                Address::where('user_id', $userId)->where('id', '!=', $address->id)
                    ->update(['is_default_billing' => false]);
            }

            $address->update($data);
        });

        return response()->json($address->fresh());
    }

    /**
     * DELETE /addresses/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $deleted = Address::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->delete();

        if (!$deleted) {
            return response()->json(['message' => 'Address not found.'], 404);
        }

        return response()->json(null, 204);
    }
}