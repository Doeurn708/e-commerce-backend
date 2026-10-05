<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\CloudinaryImageStore;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function __construct(private CloudinaryImageStore $images) {}


    // Get /api/products
    public function index(Request $request){
        $query = Product::with('category');

        // search (name, slug, description)
        if ($request->filled('search')) {
            $q = $request->input('search');
            $query->where(function ($builder) use ($q) {
                $builder->where('name', 'like', "%{$q}%")
                    ->orWhere('slug', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
            });
        }

        // category filter
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        // stock filter
        if ($request->input('stock') === 'in') {
            $query->where('stock', '>', 0);
        } elseif ($request->input('stock') === 'out') {
            $query->where('stock', '<=', 0);
        }

        // status filter (derived from stock, products have no status column)
        if ($request->input('status') === 'active') {
            $query->where('stock', '>', 0);
        } elseif ($request->input('status') === 'inactive') {
            $query->where('stock', '<=', 0);
        }

        // price range filter
        if ($request->filled('min_price')) {
            $query->where('price', '>=', (float) $request->input('min_price'));
        }

        if ($request->filled('max_price')) {
            $query->where('price', '<=', (float) $request->input('max_price'));
        }

        if ($request->boolean('in_stock')) {
            $query->where('stock', '>', 0);
        }

        // sorting
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

        // pagination
        $perPage = $this->perPage($request);

        $products = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'success' => true,
            'data' => $products
        ]);
    }

    /**
     * Get /api/products/trending
     *
     * "Trending" is the real sales signal, not a hand-picked list: the total
     * quantity sold in non-cancelled orders, taken from the existing
     * order_items -> orders relationship. Nothing is invented here, so a
     * product only trends because customers actually bought it.
     *
     * Out-of-stock products are dropped because they cannot be bought, and the
     * result is capped so the home page never pulls the whole catalogue.
     */
    public function trending(Request $request){
        $limit = max(1, min((int) $request->input('limit', 8), 12));

        $products = Product::query()
            ->select('products.*')
            // COALESCE matters on PostgreSQL: sum() over no rows is NULL, and
            // NULLs sort FIRST under DESC, which would put never-sold products
            // above the best sellers. The CASE guard is needed because a LEFT
            // JOIN on orders does NOT drop the matching order_items rows, so
            // without it cancelled orders would still be counted.
            ->selectRaw('COALESCE(SUM(CASE WHEN orders.id IS NOT NULL THEN order_items.quantity END), 0) as sold_quantity')
            ->selectRaw('COUNT(orders.id) as order_items_count')
            ->leftJoin('order_items', 'order_items.product_id', '=', 'products.id')
            // The status filter belongs in the ON clause, not in WHERE: putting
            // it in WHERE would turn the outer joins back into inner ones and
            // discard products whose orders were all cancelled.
            ->leftJoin('orders', function ($join) {
                $join->on('orders.id', '=', 'order_items.order_id')
                     ->where('orders.status', '!=', 'cancelled');
            })
            ->where('products.stock', '>', 0)
            ->groupBy('products.id')
            // Most units first, then most orders, then newest as a stable tiebreak.
            ->orderByDesc('sold_quantity')
            ->orderByDesc('order_items_count')
            ->orderByDesc('products.id')
            ->limit($limit)
            ->get();

        // Eager load after the aggregate query so the join cannot change the
        // grouping, and no category is fetched per row.
        $products->load('category');

        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    }

    // Get /api/products/{id}/related
    public function related(Request $request, $id){
        $product = Product::findOrFail($id);

        $related = Product::with('category')
            ->where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->inStock()
            ->latest()
            ->limit((int) $request->integer('limit', 4))
            ->get();

        return response()->json([
            'success' => true,
            'data' => $related,
        ]);
    }

    /**
     * Clamp the requested page size to a sane range.
     */
    private function perPage(Request $request): int
    {
        return max(1, min((int) $request->input('per_page', 12), 100));
    }

    // Post /api/products
    public function store(Request $request){
        $validated = $request->validate([
            'category_id' => ['required','exists:categories,id'],
            'name' => ['required','string','max:255'],
            'description' => ['nullable','string'],
            'price' => ['required','numeric','min:0'],
            'stock' => ['required','integer','min:0'],
        ]);

        $validated['slug'] = Str::slug($request->name);
        $this->applyImage($validated, $request);

        $product = Product::create($validated);
        $product->load('category');

        return response()->json([
            'success' => true,
            'message' => 'Product created successfully',
            'data' => $product
        ],201);
    }

    /**
     * Copy the resolved Cloudinary image onto the attributes about to be saved.
     *
     * `image` keeps holding the delivery URL for the storefront, while
     * image_url/image_public_id are the explicit pair the SPA and any later
     * cleanup read. A missing $product means a create, so there is no previous
     * asset to remove.
     */
    private function applyImage(array &$validated, Request $request, ?Product $product = null): void
    {
        $image = $this->images->resolve(
            $request,
            'image',
            'products',
            $product?->image,
            $product?->image_public_id,
        );

        $validated['image'] = $image['url'];
        $validated['image_url'] = $image['url'];
        $validated['image_public_id'] = $image['public_id'];
    }

    // Get /api/products/{id}
    public function show($id){
        // The storefront links to /product/{slug}, so the key may be either a
        // numeric id or a slug. Feeding a slug straight into findOrFail() would
        // hit the bigint primary key and raise a PostgreSQL type error.
        $product = Product::where('slug', $id)
            ->when(is_numeric($id), fn ($query) => $query->orWhere('id', (int) $id))
            ->firstOrFail();

        $product->load('category');

        return response()->json([
            'success' => true,
            'data' => $product,
        ]);
    }

    // Put / PATCH /api/products/{id}
    public function update(Request $request , $id){
        $validated = $request->validate([
            'category_id' => ['required','exists:categories,id'],
            'name' => ['required','string','max:255'],
            'description' => ['nullable','string'],
            'price' => ['required','numeric','min:0'],
            'stock' => ['required','integer','min:0'],
        ]);

        $product = Product::findOrFail($id);

        $validated['slug'] = Str::slug($request->name);
        $this->applyImage($validated, $request, $product);

        $product->update($validated);
        $product->load('category');

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully',
            'data' => $product,
        ]);
    }

    // Delete /api/products/{id}
    public function destroy($id){
        $product = Product::findOrFail($id);

        // Remove the Cloudinary asset first: once the row is gone the URL is
        // lost and the file would be orphaned in the cloud.
        $this->images->delete($product->image ?? $product->image_url, $product->image_public_id);

        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully',
        ]);
    }
}