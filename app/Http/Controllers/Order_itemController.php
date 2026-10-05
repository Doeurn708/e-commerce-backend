<?php

namespace App\Http\Controllers;

use App\Models\Order_item;
use Illuminate\Http\Request;

class Order_itemController extends Controller
{
    // Get /api/order-items
    public function index(){
        $items = Order_item::with(['order.user', 'product'])->latest()->paginate(10);

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    // Get /api/order-items/{id}
    public function show($id){
        $item = Order_item::with(['order.user', 'product'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $item,
        ]);
    }
}