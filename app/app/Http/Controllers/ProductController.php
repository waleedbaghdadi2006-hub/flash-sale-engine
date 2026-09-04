<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Requests\StoreProductRequest;

class ProductController extends Controller
{
    /**
     * GET /products
     *
     * Supports:
     *   ?category=slug-or-id   filter by category (and its subcategories)
     *   ?active=1|0            filter by is_active
     *   ?search=term           search name/description
     *   ?min_price=, max_price=
     *   ?sort=price_asc|price_desc|newest|oldest
     *   ?per_page=, page=
     */
    public function index(Request $request): JsonResponse
    {
        $query = Product::query()
            ->with(['category', 'images', 'inventory']);

        // Filter by category (accepts either the category id or slug)
        if ($request->filled('category')) {
            $categoryParam = $request->query('category');

            $category = Category::query()
                ->where('id', $categoryParam)
                ->orWhere('slug', $categoryParam)
                ->first();

            if (!$category) {
                return response()->json([
                    'message' => 'Category not found.',
                ], 404);
            }

            // Include the category itself plus any direct children,
            // since categories are a self-referencing tree.
            $categoryIds = Category::query()
                ->where('id', $category->id)
                ->orWhere('parent_id', $category->id)
                ->pluck('id');

            $query->whereIn('category_id', $categoryIds);
        }

        // Filter by active status (defaults to active-only unless explicitly requested)
        if ($request->filled('active')) {
            $query->where('is_active', $request->boolean('active'));
        } else {
            $query->where('is_active', true);
        }

        // Simple text search across name and description
        if ($request->filled('search')) {
            $term = $request->query('search');
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%");
            });
        }

        // Price range filters
        if ($request->filled('min_price')) {
            $query->where('base_price', '>=', $request->query('min_price'));
        }
        if ($request->filled('max_price')) {
            $query->where('base_price', '<=', $request->query('max_price'));
        }

        // Sorting
        switch ($request->query('sort')) {
            case 'price_asc':
                $query->orderBy('base_price', 'asc');
                break;
            case 'price_desc':
                $query->orderBy('base_price', 'desc');
                break;
            case 'oldest':
                $query->orderBy('created_at', 'asc');
                break;
            case 'newest':
            default:
                $query->orderBy('created_at', 'desc');
                break;
        }

        $perPage = (int) $request->query('per_page', 15);
        $perPage = max(1, min($perPage, 100)); // guard against absurd page sizes

        $products = $query->paginate($perPage);

        return response()->json($products);
    }

    /**
     * GET /products/{idOrSlug}
     */
    /**
     * GET /products/{idOrSlug}
     */
    public function show(string $idOrSlug): JsonResponse
    {
        $product = Product::query()
            ->with(['category', 'images', 'inventory'])
            ->where(function ($query) use ($idOrSlug) {
                $query->where('id', $idOrSlug)
                    ->orWhere('slug', $idOrSlug);
            })
            ->first();

        if (!$product) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }

        return response()->json($product);
    }

    /**
     * POST /products
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        // Validation (rules, messages) lives in StoreProductRequest.
        // Laravel runs it automatically before this method executes and
        // returns a 422 with the error bag if it fails.
        $data = $request->validated();

        $product = DB::transaction(function () use ($data) {
            $product = Product::create([
                'category_id' => $data['category_id'] ?? null,
                'name' => $data['name'],
                'slug' => $data['slug'] ?? Str::slug($data['name']) . '-' . Str::random(6),
                'description' => $data['description'] ?? null,
                'base_price' => $data['base_price'],
                'currency' => $data['currency'] ?? 'USD',
                'sku' => $data['sku'],
                'is_active' => $data['is_active'] ?? true,
            ]);

            // inventory is a 1:1 record — every product should get one
            $product->inventory()->create([
                'quantity_available' => $data['quantity_available'] ?? 0,
                'quantity_reserved' => 0,
                'version' => 0,
            ]);

            if (!empty($data['images'])) {
                foreach ($data['images'] as $image) {
                    $product->images()->create([
                        'url' => $image['url'],
                        'alt_text' => $image['alt_text'] ?? null,
                        'sort_order' => $image['sort_order'] ?? 0,
                    ]);
                }
            }

            return $product;
        });

        return response()->json(
            $product->load(['category', 'images', 'inventory']),
            201
        );
    }

    /**
     * PUT/PATCH /products/{id}
     */
    public function update(UpdateProductRequest $request, int $id): JsonResponse
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }

        // Validation (rules, messages, unique-ignore via route id) lives in
        // UpdateProductRequest. Laravel runs it automatically before this
        // method executes and returns a 422 with the error bag if it fails.
        $product->update($request->validated());

        return response()->json(
            $product->fresh(['category', 'images', 'inventory'])
        );
    }

    /**
     * DELETE /products/{id}
     *
     * Soft delete — products has a deleted_at column, and order_items /
     * cart_items reference products with RESTRICT, so hard-deleting a
     * product that has ever been ordered would violate a foreign key.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $force = $request->boolean('force');

        // If force deleting, search including soft-deleted items
        $product = $force
            ? Product::withTrashed()->find($id)
            : Product::find($id);

        if (! $product) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }

        if ($force) {
            $product->forceDelete();

            return response()->json([
                'message' => 'Product permanently deleted.',
            ]);
        }

        $product->delete(); // Soft delete

        return response()->json([
            'message' => 'Product deleted.',
        ]);
    }

    /**
     * POST /products/{id}/restore
     *
     * Restores a soft-deleted product.
     */
    public function restore(int $id): JsonResponse
    {
        $product = Product::withTrashed()->find($id);

        if (! $product) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }

        if (! $product->trashed()) {
            return response()->json([
                'message' => 'Product is not deleted.',
            ], 400);
        }

        $product->restore();

        return response()->json([
            'message' => 'Product restored successfully.',
            'product' => $product->fresh(['category', 'images', 'inventory']),
        ]);
    }
}
