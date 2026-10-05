<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    // Only admins can manage payments
    private function authorizeAdmin(Request $request): void
    {
        if ($request->user()->role !== 'admin') {
            abort(403, 'Admin access required');
        }
    }

    // Get /api/payments
    public function index(Request $request){
        $this->authorizeAdmin($request);

        $payments = Payment::with('order.user')->latest()->paginate(10);

        return response()->json([
            'success' => true,
            'data' => $payments,
        ]);
    }

    // Post /api/payments
    public function store(Request $request){
        $validated = $request->validate([
            'order_id' => ['required','exists:orders,id'],
            'amount' => ['required','numeric','min:0'],
            'payment_method' => ['nullable','string','max:100'],
            'payment_status' => ['sometimes','in:paid,unpaid,refunded'],
            'transaction_id' => ['nullable','string','max:255'],
        ]);

        $order = Order::findOrFail($validated['order_id']);

        // Customer can only mark payment for their own order
        if ($request->user()->role !== 'admin' && $order->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }

        $payment = Payment::create([
            'order_id' => $validated['order_id'],
            'amount' => $validated['amount'],
            'payment_method' => $validated['payment_method'] ?? null,
            'payment_status' => $validated['payment_status'] ?? 'unpaid',
            'transaction_id' => $validated['transaction_id'] ?? null,
            'paid_at' => ($validated['payment_status'] ?? 'unpaid') === 'paid' ? now() : null,
        ]);

        $payment->load('order.user');

        return response()->json([
            'success' => true,
            'message' => 'Payment recorded successfully',
            'data' => $payment,
        ],201);
    }

    // Get /api/payments/{id}
    public function show(Request $request, $id){
        $this->authorizeAdmin($request);

        $payment = Payment::with('order.user')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $payment,
        ]);
    }

    // Patch /api/payments/{id}
    public function update(Request $request, $id){
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'amount' => ['sometimes','numeric','min:0'],
            'payment_method' => ['nullable','string','max:100'],
            'payment_status' => ['sometimes','in:paid,unpaid,refunded'],
            'transaction_id' => ['nullable','string','max:255'],
        ]);

        $payment = Payment::findOrFail($id);

        $payment->fill($validated);

        // Mark paid_at when marked as paid
        if (($validated['payment_status'] ?? $payment->payment_status) === 'paid' && $payment->paid_at === null) {
            $payment->paid_at = now();
        }

        $payment->save();

        $payment->load('order.user');

        return response()->json([
            'success' => true,
            'message' => 'Payment updated successfully',
            'data' => $payment,
        ]);
    }

    // Delete /api/payments/{id}
    public function destroy(Request $request, $id){
        $this->authorizeAdmin($request);

        $payment = Payment::findOrFail($id);
        $payment->delete();

        return response()->json([
            'success' => true,
            'message' => 'Payment deleted successfully',
        ]);
    }
}