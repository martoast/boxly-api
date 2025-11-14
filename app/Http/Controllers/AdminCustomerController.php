<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminCreateUserRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class AdminCustomerController extends Controller
{
    
    public function index(Request $request)
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:500',
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        $perPage = $request->input('per_page') ?? $request->input('limit') ?? 20;

        $query = User::withCount(['orders', 'activeOrders'])
            ->where('role', 'customer');

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->has('active_only') && $request->active_only) {
            $query->has('activeOrders');
        }

        $total = $query->count();

        $customers = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $customers,
            'total' => $total,
        ]);
    }

    /**
     * Create a new customer (admin-created user without password)
     */
    public function store(AdminCreateUserRequest $request)
    {
        DB::beginTransaction();

        try {
            // Generate a temporary password that user must change
            $temporaryPassword = Str::random(32);
            
            $userData = [
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'password' => Hash::make($temporaryPassword),
                'user_type' => $request->user_type,
                'preferred_language' => $request->preferred_language ?? 'es',
                'role' => 'customer',
                'registration_source' => json_encode([
                    'source' => 'admin_created',
                    'admin_id' => $request->user()->id,
                    'created_at' => now()->toISOString(),
                ]),
                // Mark as admin-created so user must set password
                'password_set' => false,
            ];

            // Add address fields if provided
            if ($request->filled('street')) {
                $userData['street'] = $request->street;
            }
            if ($request->filled('exterior_number')) {
                $userData['exterior_number'] = $request->exterior_number;
            }
            if ($request->filled('interior_number')) {
                $userData['interior_number'] = $request->interior_number;
            }
            if ($request->filled('colonia')) {
                $userData['colonia'] = $request->colonia;
            }
            if ($request->filled('municipio')) {
                $userData['municipio'] = $request->municipio;
            }
            if ($request->filled('estado')) {
                $userData['estado'] = $request->estado;
            }
            if ($request->filled('postal_code')) {
                $userData['postal_code'] = $request->postal_code;
            }

            $user = User::create($userData);

            DB::commit();

            Log::info('Admin created new customer', [
                'admin_id' => $request->user()->id,
                'customer_id' => $user->id,
                'customer_email' => $user->email,
                'customer_name' => $user->name,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Customer created successfully. User can set their password by using "Forgot Password" feature.',
                'data' => $user->loadCount(['orders', 'activeOrders'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Admin failed to create customer', [
                'admin_id' => $request->user()->id,
                'email' => $request->email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create customer. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Display the specified customer.
     */
    public function show(User $customer)
    {
        if ($customer->role !== 'customer') {
            return response()->json([
                'success' => false,
                'message' => 'User is not a customer'
            ], 404);
        }

        $customer->loadCount(['orders', 'activeOrders']);
        $customer->load(['orders' => function ($query) {
            $query->latest()->limit(5);
        }]);

        $stats = [
            'total_spent' => $customer->orders()->sum('amount_paid'),
            'total_orders' => $customer->orders_count,
            'active_orders' => $customer->active_orders_count,
            'average_order_value' => $customer->orders()->avg('amount_paid') ?? 0,
            'member_since' => $customer->created_at->diffForHumans(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'customer' => $customer,
                'stats' => $stats
            ]
        ]);
    }

    /**
     * Update the specified customer
     */
    public function update(Request $request, User $customer)
    {
        if ($customer->role !== 'customer') {
            return response()->json([
                'success' => false,
                'message' => 'User is not a customer'
            ], 404);
        }

        $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => [
                'sometimes',
                'required',
                'string',
                'max:20',
                'regex:/^[\+]?[(]?[0-9]{1,4}[)]?[-\s\.]?[(]?[0-9]{1,4}[)]?[-\s\.]?[0-9]{1,12}$/'
            ],
            'user_type' => [
                'nullable',
                \Illuminate\Validation\Rule::in([User::TYPE_EXPAT, User::TYPE_BUSINESS, User::TYPE_SHOPPER])
            ],
            'preferred_language' => [
                'nullable',
                \Illuminate\Validation\Rule::in(['en', 'es'])
            ],
            'street' => ['nullable', 'string', 'max:255'],
            'exterior_number' => ['nullable', 'string', 'max:50'],
            'interior_number' => ['nullable', 'string', 'max:50'],
            'colonia' => ['nullable', 'string', 'max:255'],
            'municipio' => ['nullable', 'string', 'max:255'],
            'estado' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:10'],
        ]);

        DB::beginTransaction();

        try {
            $customer->update($request->only([
                'name',
                'phone',
                'user_type',
                'preferred_language',
                'street',
                'exterior_number',
                'interior_number',
                'colonia',
                'municipio',
                'estado',
                'postal_code',
            ]));

            DB::commit();

            Log::info('Admin updated customer', [
                'admin_id' => $request->user()->id,
                'customer_id' => $customer->id,
                'customer_email' => $customer->email,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Customer updated successfully',
                'data' => $customer->fresh()->loadCount(['orders', 'activeOrders'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Admin failed to update customer', [
                'admin_id' => $request->user()->id,
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update customer',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get customer's orders
     */
    public function orders(User $customer)
    {
        if ($customer->role !== 'customer') {
            return response()->json([
                'success' => false,
                'message' => 'User is not a customer'
            ], 404);
        }

        $orders = $customer->orders()
            ->with('items')
            ->latest()
            ->paginate(10);

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    /**
     * Get customer's current collecting orders
     */
    public function collectingOrders(User $customer)
    {
        if ($customer->role !== 'customer') {
            return response()->json([
                'success' => false,
                'message' => 'User is not a customer'
            ], 404);
        }

        $orders = $customer->collectingOrders()
            ->with('items')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }
}