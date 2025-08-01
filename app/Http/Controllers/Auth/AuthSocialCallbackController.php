<?php

namespace App\Http\Controllers\Auth;

use App\Enums\SocialProviderEnum;
use App\Http\Controllers\Controller;
use App\Jobs\SendFunnelCaptureWebhookJob;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

final class AuthSocialCallbackController extends Controller
{
    public function __invoke(SocialProviderEnum $provider)
    {
        try {
            // Get user data from OAuth provider
            $socialUser = Socialite::driver($provider->value)->stateless()->user();

            // Check if user already exists by email
            $user = User::where('email', $socialUser->email)->first();

            if ($user) {
                // User exists - update provider if needed
                if (!$user->provider) {
                    $user->update(['provider' => $provider->value]);
                }

                // Log the user in (simple Auth::login like your working example)
                Auth::login($user);

                // Check if they need to complete profile (phone number required)
                if (!$user->phone) {
                    return redirect()->to(config('app.frontend_url').'/app/account/complete-profile');
                }

                // Redirect to app
                return redirect()->to(config('app.frontend_url').'/app');
            }

            // Create new user
            $user = User::create([
                'name' => $socialUser->name ?? $socialUser->nickname ?? 'User',
                'email' => $socialUser->email,
                'email_verified_at' => now(), // Auto-verify social login users
                'password' => Hash::make(Str::random(32)), // Random password since they use OAuth
                'provider' => $provider->value,
                'preferred_language' => 'es', // Default to Spanish
            ]);

            // Create Stripe customer if using Cashier
            if (method_exists($user, 'createAsStripeCustomer')) {
                try {
                    $user->createAsStripeCustomer([
                        'name' => $user->name,
                        'email' => $user->email,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to create Stripe customer for OAuth user: ' . $e->getMessage());
                }
            }

            // Send to GoHighLevel CRM for new social registrations
            try {
                SendFunnelCaptureWebhookJob::dispatch(
                    $user->name,
                    $user->email,
                    '' // No phone yet for social logins
                );
                
                Log::info('Dispatched GoHighLevel webhook job for new social user registration', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'provider' => $provider->value,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to dispatch GoHighLevel webhook job for social user: ' . $e->getMessage());
            }

            // Log the new user in
            Auth::login($user);

            // New users need to complete their profile (add phone number)
            return redirect()->to(config('app.frontend_url').'/app/account/complete-profile');

        } catch (Exception $e) {
            Log::error('Social login failed', [
                'provider' => $provider->value,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Redirect back to login with error
            return redirect()->to(config('app.frontend_url').'/login?error=social_auth_failed');
        }
    }
}