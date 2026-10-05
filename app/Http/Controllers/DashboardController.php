<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    // Only admins can access dashboard stats
    private function authorizeAdmin(Request $request): void
    {
        if ($request->user()->role !== 'admin') {
            abort(403, 'Admin access required');
        }
    }

    // Get /api/admin/dashboard
    public function index(Request $request){
        $this->authorizeAdmin($request);

        $stats = [
            'products_total' => Product::count(),
            'categories_total' => Category::count(),
            'orders_total' => Order::count(),
            'customers_total' => User::where('role', 'customer')->count(),
            'users_total' => User::count(),
            'revenue_total' => (float) Order::where('status', '!=', 'cancelled')->sum('total_price'),
            'pending_orders' => Order::where('status', 'pending')->count(),
            'low_stock_total' => Product::where('stock', '<=', 5)->count(),
        ];

        $recentOrders = Order::with(['user', 'payment'])
            ->latest()
            ->limit(5)
            ->get(['id', 'user_id', 'total_price', 'status', 'created_at']);

        $recentProducts = Product::with('category')->latest()->limit(5)->get();

        $lowStockProducts = Product::with('category')
            ->where('stock', '<=', 5)
            ->orderBy('stock')
            ->limit(6)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'stats' => $stats,
                'recent_orders' => $recentOrders,
                'recent_products' => $recentProducts,
                'low_stock_products' => $lowStockProducts,
            ],
        ]);
    }
}