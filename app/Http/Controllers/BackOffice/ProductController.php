<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
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
        // Apply middleware to all routes except for 'index' or 'show' if needed
        $this->middleware(['auth:api', 'role:Admin'])->except(['index', 'show']);
    }

    public function index(Request $request)
    {
        try {
            // Filters
            $filterName = $request->input('name');
            $filterCategory = $request->input('category_id');

            // Sorting
            $sortBy = $request->input('sort_by', 'created_at');
            $sortOrder = Str::upper($request->input('sort_order', 'desc'));

            // Pagination
            $paginate = filter_var($request->input('paginate'), FILTER_VALIDATE_BOOLEAN);
            $perPage = $request->input('per_page', 10);
            $page = (int) $request->input('page', 1);

            // Build query
            $query = Product::query()
                ->when($filterName, fn($q) => $q->where('name', 'LIKE', "%{$filterName}%"))
                ->when($filterCategory, fn($q) => $q->where('category_id', $filterCategory))
                ->orderBy($sortBy, $sortOrder);

            // Cache key for optimization
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
        // Start transaction
        DB::beginTransaction();
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'price' => 'required|numeric',
                'site_id' => 'required|exists:sites,id',
                'category_id' => 'required|exists:categories,id',
                'images' => 'nullable|array',
                'images.*' => 'image|mimes:jpeg,png,jpg,gif|max:2048', // Validate each image
            ]);

            $product = Product::create($request->all());

            $images = handleImageUpload($request, 'images', 'product_images', true);

            foreach ($images as $imageData) {
                $product->images()->create([
                    'image_path' => $imageData['path'],
                    'is_primary' => $imageData['is_primary'],
                ]);
            }

            // Commit transaction
            DB::commit();

            return response()->json(new ProductResource($product), 201);
        } catch (\Exception $e) {
            // Rollback transaction on error
            DB::rollBack();

            // Handle the exception (e.g., log it, rethrow, etc.)
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
                'images.*' => 'image|mimes:jpeg,png,jpg,gif|max:2048', // Validate each image
            ]);

            // Update product details
            $product->update($request->only(['name', 'description', 'price', 'site_id', 'category_id']));

            // Check if new images are provided
            if ($request->hasFile('images')) {
                // Delete old images from storage
                foreach ($product->images as $oldImage) {
                    Storage::delete($oldImage->image_path);
                    $oldImage->delete();
                }

                $images = handleImageUpload($request, 'images', 'product_images', true);

                foreach ($images as $imageData) {
                    $product->images()->create([
                        'image_path' => $imageData['path'],
                        'is_primary' => $imageData['is_primary'],
                    ]);
                }
            }

            DB::commit();
            return response()->json(new ProductResource($product));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }


    public function destroy(Product $product)
    {
        // Delete associated images from storage
        foreach ($product->images as $image) {
            Storage::delete($image->image_path);
            $image->delete(); // Delete image record from the database
        }

        // Now delete the product
        $product->delete();

        return response()->json(['message' => 'Product and associated images deleted successfully']);
    }

}
