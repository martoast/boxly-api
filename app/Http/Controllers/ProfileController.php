<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProfileRequest;
use App\Jobs\SendFunnelCaptureWebhookJob;
use Illuminate\Http\Request;
use App\Models\Order;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;

class ProfileController extends Controller
{
    /**
     * Get the authenticated user's profile
     */
    public function show(Request $request)
    {
        $user = $request->user();
        
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'user_type' => $user->user_type,
                'user_type_label' => $user->getUserTypeLabel(),
                'is_business' => $user->isBusiness(),
                'preferred_language' => $user->preferred_language,
                'address' => $user->address,
                'has_complete_address' => $user->hasCompleteAddress(),
                'registration_source' => $user->getRegistrationSourceData(),
                'created_at' => $user->created_at,
                // Stats
                'total_orders' => $user->orders()->count(),
                'active_orders' => $user->orders()->whereIn('status', [
                    Order::STATUS_COLLECTING,
                    Order::STATUS_AWAITING_PACKAGES,
                    Order::STATUS_PACKAGES_COMPLETE
                ])->count(),
                'completed_orders' => $user->orders()->whereIn('status', [
                    Order::STATUS_SHIPPED,
                    Order::STATUS_DELIVERED
                ])->count(),
            ]
        ]);
    }

    /**
     * Update the authenticated user's profile
     */
    public function update(Request $request)
    {
        $user = $request->user();
        
        // Track if this is the first time setting phone
        $isFirstTimeSettingPhone = !$user->phone && $request->has('phone');
        $wasProfileIncomplete = !$user->phone;
        
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->ignore($user->id),
            ],
            'phone' => 'nullable|string|max:20',
            'preferred_language' => 'nullable|string|in:es,en',
            'street' => 'nullable|string|max:255',
            'exterior_number' => 'nullable|string|max:20',
            'interior_number' => 'nullable|string|max:20',
            'colonia' => 'nullable|string|max:100',
            'municipio' => 'nullable|string|max:100',
            'estado' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|regex:/^\d{5}$/',
            'user_type' => [
                'nullable',
                Rule::in(['expat', 'business', 'shopper']),
            ],
            'registration_source' => 'nullable|json',
        ], [
            'email.unique' => 'This email is already in use.',
            'postal_code.regex' => 'Postal code must be 5 digits.',
            'registration_source.json' => 'Invalid tracking data format.',
        ]);
        
        // Prevent changing user type if already set (optional business logic)
        if ($user->user_type && isset($validated['user_type']) && $validated['user_type'] !== $user->user_type) {
            return response()->json([
                'success' => false,
                'message' => 'User type cannot be changed once set.',
                'errors' => [
                    'user_type' => ['User type cannot be changed once set.']
                ]
            ], 422);
        }
        
        // Handle registration_source JSON encoding
        if (isset($validated['registration_source'])) {
            if (is_string($validated['registration_source'])) {
                $decoded = json_decode($validated['registration_source'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid tracking data format.',
                        'errors' => [
                            'registration_source' => ['Invalid JSON format.']
                        ]
                    ], 422);
                }
            } elseif (is_array($validated['registration_source'])) {
                $validated['registration_source'] = json_encode($validated['registration_source']);
            }
        }
        
        // Update language preference based on user type if setting for first time
        if (isset($validated['user_type']) && !$user->user_type) {
            $validated['preferred_language'] = $validated['user_type'] === 'expat' ? 'en' : 'es';
        }
        
        $user->update($validated);
        
        // If profile was incomplete and phone is now set, send to CRM
        if ($wasProfileIncomplete && $user->phone) {
            try {
                $sourceData = $user->getRegistrationSourceData();
                
                SendFunnelCaptureWebhookJob::dispatch(
                    $user->name,
                    $user->email,
                    $user->phone,
                    $user->user_type, // Can be null
                    $sourceData
                );
                
                Log::info('Sent completed profile to GoHighLevel', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'user_type' => $user->user_type,
                    'registration_source' => $sourceData,
                    'was_social_login' => !empty($user->provider),
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to send completed profile to GoHighLevel: ' . $e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => $user
        ]);
    }

    /**
     * Get user's dashboard statistics
     */
    public function dashboard(Request $request)
    {
        $user = $request->user();
        
        $stats = [
            'user_info' => [
                'user_type' => $user->user_type,
                'user_type_label' => $user->getUserTypeLabel(),
                'is_business' => $user->isBusiness(),
                'member_since' => $user->created_at->format('F Y'),
            ],
            'orders' => [
                'collecting' => $user->orders()->where('status', Order::STATUS_COLLECTING)->count(),
                'awaiting_packages' => $user->orders()->where('status', Order::STATUS_AWAITING_PACKAGES)->count(),
                'packages_complete' => $user->orders()->where('status', Order::STATUS_PACKAGES_COMPLETE)->count(),
                'in_transit' => $user->orders()->where('status', Order::STATUS_SHIPPED)->count(),
                'delivered' => $user->orders()->where('status', Order::STATUS_DELIVERED)->count(),
            ],
            'totals' => [
                'total_orders' => $user->orders()->count(),
                'total_spent' => $user->orders()->sum('amount_paid'),
                'total_items' => $user->orders()->withCount('items')->get()->sum('items_count'),
                'active_orders' => $user->orders()->whereIn('status', [
                    Order::STATUS_COLLECTING,
                    Order::STATUS_AWAITING_PACKAGES,
                    Order::STATUS_PACKAGES_COMPLETE
                ])->count(),
            ],
            'recent_activity' => [
                'recent_orders' => $user->orders()
                    ->with(['items' => function($query) {
                        $query->latest()->limit(3);
                    }])
                    ->latest()
                    ->limit(5)
                    ->get()
                    ->map(function($order) {
                        return [
                            'id' => $order->id,
                            'order_number' => $order->order_number,
                            'status' => $order->status,
                            'created_at' => $order->created_at,
                            'item_count' => $order->items->count(),
                            'amount_paid' => $order->amount_paid,
                        ];
                    }),
            ]
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }
}