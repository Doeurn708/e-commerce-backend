<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    /**
     * Only admins may change an order's status. Customers are limited to
     * cancelling their own pending order (see cancel() below).
     */
    private function authorizeAdmin(Request $request): void
    {
        if ($request->user()->role !== 'admin') {
            abort(403, 'You do not have permission to perform this action.');
        }
    }

    // Get /api/orders
    public function index(Request $request){
        $query = Order::with([
            'user',
            'orderItems.product',
            'payment',
        ]);

        // admins see every order, customers only their own
        if ($request->user()->role !== 'admin') {
            $query->where('user_id', $request->user()->id);
        }

        // search (order id, shipping address, customer name/email)
        if ($request->filled('search')) {
            $q = $request->input('search');
            $query->where(function ($builder) use ($q) {
                $builder->where('id', 'like', "%{$q}%")
                    ->orWhere('shipping_address', 'like', "%{$q}%")
                    ->orWhereHas('user', function ($user) use ($q) {
                        $user->where('name', 'like', "%{$q}%")
                            ->orWhere('email', 'like', "%{$q}%");
                    });
            });
        }

        // status filter
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // date filter (YYYY-MM-DD on created_at)
        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->input('date'));
        }

        // sorting
        switch ($request->input('sort')) {
            case 'oldest':
                $query->oldest();
                break;
            case 'amount_desc':
                $query->orderByDesc('total_price');
                break;
            case 'amount_asc':
                $query->orderBy('total_price');
                break;
            default:
                $query->latest();
        }

        // pagination
        $perPage = max(1, min((int) $request->input('per_page', 10), 100));

        $orders = $query->withCount('orderItems as items_count')->paginate($perPage)->withQueryString();

        $orders->getCollection()->transform(fn (Order $order) => $this->present($order));

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    // Post /api/orders
    public function store(Request $request){
        $validated = $request->validate([
            'payment_method' => ['nullable','in:cod,online'],
            // The checkout map supplies both the label and the pin, so they are
            // validated together: an address without coordinates cannot be
            // delivered to and vice versa.
            'shipping_address' => ['required','string','max:255'],
            'latitude' => ['required','numeric','between:-90,90'],
            'longitude' => ['required','numeric','between:-180,180'],
            'phone' => ['required','string','max:20'],
            'city' => ['nullable','string','max:255'],
            'province' => ['nullable','string','max:255'],
            'postal_code' => ['nullable','string','max:20'],
            'items' => ['required','array','min:1'],
            'items.*.product_id' => ['required','exists:products,id'],
            'items.*.quantity' => ['required','integer','min:1'],
        ]);

        $order = DB::transaction(function () use ($request, $validated) {
            $total = 0;

            $order = Order::create([
                'user_id' => $request->user()->id,
                'total_price' => 0,
                'status' => 'pending',
                'shipping_address' => $validated['shipping_address'],
                'latitude' => $validated['latitude'],
                'longitude' => $validated['longitude'],
                'phone' => $validated['phone'],
                'city' => $validated['city'] ?? null,
                'province' => $validated['province'] ?? null,
                'postal_code' => $validated['postal_code'] ?? null,
                'payment_method' => $validated['payment_method'] ?? 'cod',
            ]);

            foreach($validated['items'] as $item){
                $product = Product::findOrFail($item['product_id']);

                if($product->stock < $item['quantity']){
                    abort(422, "Not enough stock for {$product->name}");
                }

                $price = $product->price;
                $subtotal = $price * $item['quantity'];

                $order->orderItems()->create([
                    'product_id' => $product->id,
                    'quantity' => $item['quantity'],
                    'price' => $price,
                ]);

                $product->decrement('stock', $item['quantity']);

                $total += $subtotal;
            }

            $order->update([
                'total_price' => $total,
            ]);

            return $order;
        });

        $order->load([
            'user',
            'orderItems.product',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Order created successfully',
            'data' => $this->present($order),
        ],201);
    }

    // Get /api/orders/{id}
    public function show(Request $request , $id){
        $order = Order::with([
            'user',
            'orderItems.product',
            'payment',
        ])->withCount('orderItems as items_count')->findOrFail($id);

        $isAdmin = $request->user()->role === 'admin';
        if (!$isAdmin && $order->user_id !== $request->user()->id){
            return response()->json([
                'message' => 'Unauthorized',
            ],403);
        }

        return response()->json([
            'success' => true,
            'data' => $this->present($order)
        ]);
    }

    // Patch /api/orders/{id}/cancel
    public function cancel(Request $request, $id){
        $order = Order::with('orderItems.product')->findOrFail($id);

        // only the owner or an admin may cancel, and only while still pending
        if ($request->user()->role !== 'admin' && $order->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Unauthorized',
            ],403);
        }

        if ($order->status !== 'pending') {
            return response()->json([
                'message' => 'Only pending orders can be cancelled.',
            ],422);
        }

        DB::transaction(function () use ($order) {
            $order->update(['status' => 'cancelled']);

            // return the reserved stock to the products
            foreach ($order->orderItems as $item) {
                $item->product?->increment('stock', $item->quantity);
            }
        });

        $order->load(['user', 'orderItems.product', 'payment']);

        return response()->json([
            'success' => true,
            'message' => 'Order cancelled successfully',
            'data' => $this->present($order),
        ]);
    }

    // Patch /api/orders/{id}/status
    public function updateStatus(Request $request , $id){
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'status' => [
                'required','in:pending,processing,completed,cancelled',
            ],
        ]);

        $order = Order::findOrFail($id);

        $order->update([
            'status' => $validated['status'],
        ]);

        $order->load([
            'user',
            'orderItems.product',
            'payment',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Order status updated successfully',
            'data' => $this->present($order),
        ]);
    }

    /**
     * Shape an order for the frontend: alias the orderItems relation to
     * "items" and derive the totals/payment fields the UI renders.
     */
    private function present(Order $order): Order
    {
        $order->loadMissing(['orderItems.product', 'payment']);

        $order->setAttribute('items', $order->orderItems);
        $order->setAttribute('subtotal', (float) $order->total_price);
        $order->setAttribute('shipping', 0.0);
        $order->setAttribute('discount', 0.0);
        $order->setAttribute('payment_status', $order->payment?->payment_status ?? 'unpaid');
        $order->setAttribute('payment_method', $order->payment?->payment_method ?? $order->payment_method);

        return $order;
    }
}