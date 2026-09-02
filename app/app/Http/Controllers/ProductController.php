<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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

            if (! $category) {
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
    public function show(string $idOrSlug): JsonResponse
    {
        $product = Product::query()
            ->with(['category', 'images', 'inventory'])
            ->where('id', $idOrSlug)
            ->orWhere('slug', $idOrSlug)
            ->first();

        if (! $product) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }

        return response()->json($product);
    }

    /**
     * POST /products
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'category_id' => ['nullable', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:280', 'unique:products,slug'],
            'description' => ['nullable', 'string'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'sku' => ['required', 'string', 'max:100', 'unique:products,sku'],
            'is_active' => ['nullable', 'boolean'],
            'quantity_available' => ['nullable', 'integer', 'min:0'],
            'images' => ['nullable', 'array'],
            'images.*.url' => ['required_with:images', 'string', 'max:500'],
            'images.*.alt_text' => ['nullable', 'string', 'max:255'],
            'images.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

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

            if (! empty($data['images'])) {
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
    public function update(Request $request, int $id): JsonResponse
    {
        $product = Product::find($id);

        if (! $product) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'category_id' => ['nullable', 'exists:categories,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => [
                'sometimes', 'string', 'max:280',
                Rule::unique('products', 'slug')->ignore($product->id),
            ],
            'description' => ['nullable', 'string'],
            'base_price' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'sku' => [
                'sometimes', 'string', 'max:100',
                Rule::unique('products', 'sku')->ignore($product->id),
            ],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $product->update($validator->validated());

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
    public function destroy(int $id): JsonResponse
    {
        $product = Product::find($id);

        if (! $product) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }

        $product->delete(); // relies on SoftDeletes trait on the model

        return response()->json([
            'message' => 'Product deleted.',
        ]);
    }
}
