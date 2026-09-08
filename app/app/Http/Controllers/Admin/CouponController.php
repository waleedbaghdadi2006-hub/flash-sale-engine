<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Coupon\StoreCouponRequest;
use App\Http\Requests\Coupon\UpdateCouponRequest;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin-only coupon management. Every action here also re-checks
 * role === 'admin' in the request classes (see StoreCouponRequest /
 * UpdateCouponRequest), but the routes should still sit behind an
 * admin-only middleware group as the first line of defense — see the
 * routes snippet.
 */
class CouponController extends Controller
{
    /**
     * GET /admin/coupons
     *
     * Supports:
     *   ?active=1|0     filter by is_active
     *   ?search=term    match against code or description
     *   ?per_page=, page=
     */
    public function index(Request $request): JsonResponse
    {
        $query = Coupon::query();

        if ($request->filled('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        if ($request->filled('search')) {
            $term = $request->query('search');
            $escapedTerm = addcslashes((string) $term, '\\%_');
            $query->where(function ($q) use ($escapedTerm) {
                $q->where('code', 'like', "%{$escapedTerm}%")
                    ->orWhere('description', 'like', "%{$escapedTerm}%");
            });
        }

        $query->orderByDesc('created_at');

        $perPage = (int) $request->query('per_page', 15);
        $perPage = max(1, min($perPage, 100));

        return response()->json($query->paginate($perPage));
    }

    /**
     * GET /admin/coupons/{id}
     */
    public function show(int $id): JsonResponse
    {
        $coupon = Coupon::find($id);

        if (!$coupon) {
            return response()->json(['message' => 'Coupon not found.'], 404);
        }

        return response()->json($coupon);
    }

    /**
     * POST /admin/coupons
     */
    public function store(StoreCouponRequest $request): JsonResponse
    {
        $data = $request->validated();

        $coupon = Coupon::create([
            'code' => strtoupper($data['code']),
            'description' => $data['description'] ?? null,
            'discount_type' => $data['discount_type'],
            'discount_value' => $data['discount_value'],
            'min_order_amount' => $data['min_order_amount'] ?? 0,
            'max_uses' => $data['max_uses'] ?? null,
            'times_used' => 0,
            'starts_at' => $data['starts_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json($coupon, 201);
    }

    /**
     * PUT/PATCH /admin/coupons/{id}
     */
    public function update(UpdateCouponRequest $request, int $id): JsonResponse
    {
        $coupon = Coupon::find($id);

        if (!$coupon) {
            return response()->json(['message' => 'Coupon not found.'], 404);
        }

        $data = $request->validated();

        if (isset($data['code'])) {
            $data['code'] = strtoupper($data['code']);
        }

        $coupon->update($data);

        return response()->json($coupon->fresh());
    }

    /**
     * DELETE /admin/coupons/{id}
     *
     * Hard delete is safe here: orders.coupon_id is ON DELETE SET NULL, so
     * any historical order that used this coupon just loses the reference
     * rather than breaking.
     */
    public function destroy(int $id): JsonResponse
    {
        $coupon = Coupon::find($id);

        if (!$coupon) {
            return response()->json(['message' => 'Coupon not found.'], 404);
        }

        $coupon->delete();

        return response()->json(['message' => 'Coupon deleted.']);
    }

    /**
     * POST /admin/coupons/{id}/toggle
     *
     * Quick activate/deactivate without a full update payload.
     */
    public function toggle(int $id): JsonResponse
    {
        $coupon = Coupon::find($id);

        if (!$coupon) {
            return response()->json(['message' => 'Coupon not found.'], 404);
        }

        $coupon->update(['is_active' => !$coupon->is_active]);

        return response()->json($coupon->fresh());
    }
}