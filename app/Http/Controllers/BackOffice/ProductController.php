<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:api', 'role:Admin'])->except(['index', 'show']);
    }

    public function index(Request $request)
    {
        try {
            $filterName = $request->input('name');
            $filterCategory = $request->input('category_id');
            $sortBy = $request->input('sort_by', 'created_at');
            $sortOrder = Str::upper($request->input('sort_order', 'desc'));
            $paginate = filter_var($request->input('paginate'), FILTER_VALIDATE_BOOLEAN);
            $perPage = min($request->input('per_page', 10), 100); // Limit per_page to 100
            $page = (int) $request->input('page', 1);

            $query = Product::query()
                ->when($filterName, fn($q) => $q->where('name', 'LIKE', "%{$filterName}%"))
                ->when($filterCategory, fn($q) => $q->where('category_id', $filterCategory))
                ->orderBy($sortBy, $sortOrder);

            $cacheKey = "products_{$filterName}_{$filterCategory}_{$sortBy}_{$sortOrder}_{$perPage}_page_{$page}";

            if ($paginate) {
                $products = Cache::remember($cacheKey, 3600, function () use ($query, $perPage, $page) {
                    return $query->paginate($perPage, ['*'], 'page', $page);
                });

                return response()->json([
                    'success' => true,
                    'data' => ProductResource::collection($products->items()),
                    'pagination' => [
                        'totalItems' => $products->total(),
                        'currentPage' => $products->currentPage(),
                        'totalPages' => $products->lastPage(),
                        'limit' => $products->perPage(),
                    ],
                ]);
            } else {
                $products = Cache::remember($cacheKey, 3600, function () use ($query) {
                    return $query->get();
                });

                return response()->json([
                    'success' => true,
                    'data' => ProductResource::collection($products),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error fetching products: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching products.',
            ], 500);
        }
    }

    public function store(Request $request)
    {
        DB::beginTransaction();
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'price' => 'required|numeric',
                'site_id' => 'required|exists:sites,id',
                'category_id' => 'required|exists:categories,id',
                'images' => 'nullable|array',
                'images.*' => 'image|mimes:jpeg,png,jpg,gif|max:2048',
                'variants' => 'nullable|array',
                'variants.*.size' => 'required|string',
                'variants.*.color' => 'required|string',
                'variants.*.price' => 'required|numeric',
                'variants.*.stock' => 'required|integer',
            ]);

            $product = Product::create($request->only(['name', 'description', 'price', 'site_id', 'category_id']));

            // Handle images
            if ($request->hasFile('images')) {
                $images = $this->handleImageUpload($request, 'images', 'product_images', true);
                foreach ($images as $imageData) {
                    $product->images()->create([
                        'image_path' => $imageData['path'],
                        'is_primary' => $imageData['is_primary'],
                    ]);
                }
            }

            // Handle variants
            if ($request->has('variants')) {
                foreach ($request->variants as $variantData) {
                    $product->variants()->create([
                        'size' => $variantData['size'],
                        'color' => $variantData['color'],
                        'price' => $variantData['price'],
                        'stock' => $variantData['stock'],
                    ]);
                }
            }

            DB::commit();
            Cache::forget('products_*'); // Invalidate cache
            return response()->json(new ProductResource($product), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error storing product: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function show(Product $product)
    {
        return response()->json(new ProductResource($product));
    }

    public function update(Request $request, Product $product)
    {
        DB::beginTransaction();
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'price' => 'required|numeric',
                'site_id' => 'required|exists:sites,id',
                'category_id' => 'required|exists:categories,id',
                'images' => 'nullable|array',
                'images.*' => 'image|mimes:jpeg,png,jpg,gif|max:2048',
                'variants' => 'nullable|array',
                'variants.*.id' => 'nullable|exists:product_variants,id', // For updating existing variants
                'variants.*.size' => 'required|string',
                'variants.*.color' => 'required|string',
                'variants.*.price' => 'required|numeric',
                'variants.*.stock' => 'required|integer',
            ]);

            // Update product details
            $product->update($request->only(['name', 'description', 'price', 'site_id', 'category_id']));

            // Handle images
            if ($request->hasFile('images')) {
                foreach ($product->images as $oldImage) {
                    Storage::delete($oldImage->image_path);
                    $oldImage->delete();
                }

                $images = $this->handleImageUpload($request, 'images', 'product_images', true);
                foreach ($images as $imageData) {
                    $product->images()->create([
                        'image_path' => $imageData['path'],
                        'is_primary' => $imageData['is_primary'],
                    ]);
                }
            }

            // Handle variants
            if ($request->has('variants')) {
                foreach ($request->variants as $variantData) {
                    if (isset($variantData['id'])) {
                        // Update existing variant
                        $variant = ProductVariant::find($variantData['id']);
                        $variant->update([
                            'size' => $variantData['size'],
                            'color' => $variantData['color'],
                            'price' => $variantData['price'],
                            'stock' => $variantData['stock'],
                        ]);
                    } else {
                        // Create new variant
                        $product->variants()->create([
                            'size' => $variantData['size'],
                            'color' => $variantData['color'],
                            'price' => $variantData['price'],
                            'stock' => $variantData['stock'],
                        ]);
                    }
                }
            }

            DB::commit();
            Cache::forget('products_*'); // Invalidate cache
            return response()->json(new ProductResource($product));
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating product: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function destroy(Product $product)
    {
        try {
            // Delete associated images
            foreach ($product->images as $image) {
                Storage::delete($image->image_path);
                $image->delete();
            }

            // Delete associated variants
            $product->variants()->delete();

            // Delete the product
            $product->delete();

            Cache::forget('products_*'); // Invalidate cache
            return response()->json(['message' => 'Product and associated images/variants deleted successfully']);
        } catch (\Exception $e) {
            Log::error('Error deleting product: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleImageUpload(Request $request, $fieldName, $storagePath, $isPrimary = false)
    {
        $images = [];
        if ($request->hasFile($fieldName)) {
            foreach ($request->file($fieldName) as $image) {
                $path = $image->store($storagePath, 'public');
                $images[] = [
                    'path' => $path,
                    'is_primary' => $isPrimary,
                ];
            }
        }
        return $images;
    }
}
