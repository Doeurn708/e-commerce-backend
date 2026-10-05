<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UsersController extends Controller
{
    // Only admins can manage users
    private function authorizeAdmin(Request $request): void
    {
        if ($request->user()->role !== 'admin') {
            abort(403, 'Admin access required');
        }
    }

    // Get /api/users
    public function index(Request $request){
        $this->authorizeAdmin($request);

        $query = User::query()
            ->withCount('orders')
            ->withSum('orders', 'total_price')
            ->with(['orders' => fn ($q) => $q->latest()]);

        // search (name, email)
        if ($request->filled('search')) {
            $q = $request->input('search');
            $query->where(function ($builder) use ($q) {
                $builder->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            });
        }

        // role filter
        if ($request->filled('role') && in_array($request->input('role'), ['customer', 'admin'])) {
            $query->where('role', $request->input('role'));
        }

        // status filter (active / suspended)
        if ($request->filled('status') && in_array($request->input('status'), ['active', 'suspended'])) {
            $query->where('is_active', $request->input('status') === 'active');
        }

        // sorting
        switch ($request->input('sort')) {
            case 'name_asc':
                $query->orderBy('name');
                break;
            case 'name_desc':
                $query->orderByDesc('name');
                break;
            case 'spent_desc':
                $query->orderByDesc('orders_sum_total_price');
                break;
            case 'orders_desc':
                $query->orderByDesc('orders_count');
                break;
            default:
                $query->latest();
        }

        // pagination
        $perPage = max(1, min((int) $request->input('per_page', 10), 100));

        $users = $query->paginate($perPage)->withQueryString();

        // The user now has a real phone column. Fall back to the phone on their
        // latest order only for accounts that have not filled it in yet.
        $users->getCollection()->transform(function ($user) {
            $user->phone = $user->phone ?: ($user->orders->first()?->phone ?? null);
            $user->setAttribute('total_spent', (float) ($user->orders_sum_total_price ?? 0));
            return $user;
        });

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    // Post /api/users
    public function store(Request $request){
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'name' => ['required','string','max:255'],
            'email' => ['required','email','unique:users,email'],
            'password' => ['required','string','min:8'],
            'role' => ['sometimes','in:customer,admin'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'] ?? 'customer',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'data' => $user,
        ],201);
    }

    // Get /api/users/{id}
    public function show(Request $request, $id){
        $this->authorizeAdmin($request);

        $user = User::with('orders')
            ->withCount('orders')
            ->withSum('orders', 'total_price')
            ->findOrFail($id);

        $user->phone = $user->phone ?: ($user->orders->first()?->phone ?? null);

        return response()->json([
            'success' => true,
            'data' => $user,
        ]);
    }

    // Put / PATCH /api/users/{id}
    public function update(Request $request , $id){
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'name' => ['required','string','max:255'],
            'email' => ['required','email','unique:users,email,'.$id],
            'password' => ['nullable','string','min:8'],
            'role' => ['sometimes','in:customer,admin'],
            'is_active' => ['sometimes','boolean'],
        ]);

        $user = User::findOrFail($id);

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->role = $validated['role'] ?? $user->role;
        $user->is_active = array_key_exists('is_active', $validated) ? $validated['is_active'] : $user->is_active;
        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'data' => $user,
        ]);
    }

    // Delete /api/users/{id}
    public function destroy(Request $request, $id){
        $this->authorizeAdmin($request);

        $user = User::findOrFail($id);
        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully',
        ]);
    }
}