<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Public account creation from the in-chat shopping assistant. A guest who
 * wants to place an order creates an account inline (name/email/phone) without
 * leaving the chat. Mirrors the social-callback user creation: random password,
 * Stripe customer, Auth::login to establish the SPA session — and ALSO returns
 * a Sanctum token the chat backend uses to act as the user.
 *
 * Passwordless: these accounts have a random password. To sign in later the
 * user uses Google/Facebook on the same email (magic-link is a future option).
 *
 * Must be mounted under the 'web' middleware group so Auth::login persists a
 * session cookie. Rate-limited (public).
 */
class ChatRegisterController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => [
                'required',
                'string',
                'max:20',
                'regex:/^[\+]?[(]?[0-9]{1,4}[)]?[-\s\.]?[(]?[0-9]{1,4}[)]?[-\s\.]?[0-9]{1,12}$/',
            ],
        ], [
            'phone.regex' => 'Please enter a valid phone number.',
        ]);

        // Email already registered → tell the chat to route them to sign-in
        // instead of silently taking over the account.
        if (User::where('email', $validated['email'])->exists()) {
            return response()->json([
                'success' => false,
                'code' => 'account_exists',
                'message' => 'An account with this email already exists. Please sign in with Google or Facebook.',
            ], 409);
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'password' => Hash::make(Str::random(32)),
            'preferred_language' => 'es',
            'user_type' => null,
            'registration_source' => ['source' => 'chat_assistant'],
        ]);

        if (method_exists($user, 'createAsStripeCustomer')) {
            try {
                $user->createAsStripeCustomer(['name' => $user->name, 'email' => $user->email]);
            } catch (\Exception $e) {
                Log::error('Chat register: Stripe customer create failed: ' . $e->getMessage());
            }
        }

        // SPA session (cookie) — makes the browser logged in, like social login.
        Auth::login($user);

        // Token for the chat backend to act as this user (PR creation, etc.).
        $token = $user->createToken('chat-assistant')->plainTextToken;

        return response()->json([
            'success' => true,
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
            ],
        ], 201);
    }
}
