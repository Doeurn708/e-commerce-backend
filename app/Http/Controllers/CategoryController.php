<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\CloudinaryImageStore;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    /**
     * Categories keep their uploads in their own folder.
     */
    private const IMAGE_FOLDER = 'categories';

    public function __construct(private CloudinaryImageStore $images) {}

    // Get /api/categories
    public function index(Request $request){
        $query = Category::with('products')->withCount('products');

        // search (name, slug)
        if ($request->filled('search')) {
            $q = $request->input('search');
            $query->where(function ($builder) use ($q) {
                $builder->where('name', 'like', "%{$q}%")
                    ->orWhere('slug', 'like', "%{$q}%");
            });
        }

        // sorting
        switch ($request->input('sort')) {
            case 'name':
                $query->orderBy('name');
                break;
            case 'products':
                $query->orderByDesc('products_count');
                break;
            default:
                $query->latest();
        }

        // paginate only when list-style query params are present (keeps the old
        // plain-list response for the public /categories call)
        $isListRequest = $request->filled('page')
            || $request->filled('per_page')
            || $request->filled('search')
            || $request->filled('sort');

        if ($isListRequest) {
            $perPage = $this->perPage($request);

            $categories = $query->paginate($perPage)->withQueryString();

            return response()->json([
                'success' => true,
                'data' => $categories
            ]);
        }

        $categories = $query->get();

        return response()->json([
            'success' => true,
            'data' => $categories
        ]);
    }

    // Get /api/categories/{id}/products
    public function products(Request $request, $id){
        $category = Category::withCount('products')->findOrFail($id);

        $query = $category->products()->with('category');

        if ($request->filled('search')) {
            $q = $request->input('search');
            $query->where(function ($builder) use ($q) {
                $builder->where('name', 'like', "%{$q}%")
                    ->orWhere('slug', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
            });
        }

        if ($request->filled('min_price')) {
            $query->where('price', '>=', (float) $request->input('min_price'));
        }

        if ($request->filled('max_price')) {
            $query->where('price', '<=', (float) $request->input('max_price'));
        }

        if ($request->boolean('in_stock')) {
            $query->where('stock', '>', 0);
        }

        switch ($request->input('sort')) {
            case 'name_asc':
                $query->orderBy('name');
                break;
            case 'name_desc':
                $query->orderByDesc('name');
                break;
            case 'price_asc':
                $query->orderBy('price');
                break;
            case 'price_desc':
                $query->orderByDesc('price');
                break;
            case 'popular':
                $query->orderByDesc('stock');
                break;
            default:
                $query->latest();
        }

        $products = $query->paginate($this->perPage($request))->withQueryString();

        return response()->json([
            'success' => true,
            'data' => [
                'category' => $category,
                'paginated' => $products,
            ],
        ]);
    }

    /**
     * Clamp the requested page size to a sane range.
     */
    private function perPage(Request $request): int
    {
        return max(1, min((int) $request->input('per_page', 12), 100));
    }

    // Post /api/categories
    public function store(Request $request){
        $validated = $request->validate([
            'name'=>['required','string','max:255'],
            'description'=>['nullable','string'],
        ]);

        $validated['slug'] = Str::slug($request->name);
        $validated['image'] = $this->images->resolveUrl($request, 'image', self::IMAGE_FOLDER);

        $category = Category::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Category created successfully',
            'data' => $category
        ],201);
    }

    // Get /api/categories/{id}
    public function show($id){
        $category = Category::with('products')->withCount('products')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $category,
        ]);
    }

    // Put / PATCH /api/categories/{id}
    public function update(Request $request , $id ){
        $validated = $request->validate([
            'name' => ['required','string','max:255'],
            'description' => ['nullable','string'],
        ]);

        $category = Category::findOrFail($id);

        // Captured before the update so the asset can be cleaned up afterwards.
        // Categories have no image_public_id column, so delete() recovers the id
        // from the URL; a non-Cloudinary value (Pinterest, local path) is ignored.
        $previousImage = $category->image;

        $validated['slug'] = Str::slug($request->name);
        $validated['image'] = $this->images->resolveUrl($request, 'image', self::IMAGE_FOLDER, $previousImage);

        $category->update($validated);

        // Only when the URL actually changed: resolveUrl returns the current one
        // when no new file arrived, and deleting that would break the record.
        if ($previousImage !== null && $previousImage !== $validated['image']) {
            $this->images->delete($previousImage);
        }

        return response()->json([
            'success' => true,
            'message' => 'Category updated successfully',
            'data' => $category
        ]);
    }

    // Delete /api/categories/{id}
    public function destroy($id)
    {
        $category = Category::findOrFail($id);

        // The row is gone first, so a failed cloud cleanup cannot leave a
        // category that can no longer be deleted. delete() never throws and
        // ignores anything that is not a Cloudinary URL of ours.
        $image = $category->image;
        $category->delete();

        if ($image !== null) {
            $this->images->delete($image);
        }

        return response()->json([
            'success' => true,
            'message' => 'Category deleted successfully',
        ]);
    }
}