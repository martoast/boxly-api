<?php

namespace App\Http\Controllers\Auth;

use App\Enums\SocialProviderEnum;
use App\Http\Controllers\Controller;
use App\Jobs\SendFunnelCaptureWebhookJob;
use App\Models\User;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

final class AuthSocialCallbackController extends Controller
{
    public function __invoke(SocialProviderEnum $provider, Request $request)
    {
        try {
            // Get user data from OAuth provider
            $socialUser = Socialite::driver($provider->value)->stateless()->user();
            
            // Decode the state parameter to get tracking data
            $trackingData = null;
            $redirectPath = '/app';
            
            if ($request->has('state')) {
                try {
                    $stateData = json_decode(base64_decode($request->get('state')), true);
                    $trackingData = $stateData['tracking'] ?? null;
                    $redirectPath = $stateData['redirect'] ?? '/app';
                } catch (\Exception $e) {
                    Log::warning('Could not decode OAuth state parameter', [
                        'state' => $request->get('state'),
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Check if user already exists by email
            $user = User::where('email', $socialUser->email)->first();

            if ($user) {
                // User exists - update provider if needed
                if (!$user->provider) {
                    $user->update(['provider' => $provider->value]);
                }
                
                // If user doesn't have registration source and we have tracking data, update it
                if (!$user->registration_source && $trackingData) {
                    $user->update(['registration_source' => $trackingData]);
                }

                // Log the user in
                Auth::login($user);

                // Check if they need to complete profile
                if (!$user->phone || !$user->user_type) {
                    // Pass tracking data to complete-profile page
                    $completeProfileUrl = config('app.frontend_url').'/app/account/complete-profile';
                    if ($trackingData) {
                        $completeProfileUrl .= '?tracking=' . urlencode($trackingData);
                    }
                    if ($redirectPath && $redirectPath !== '/app') {
                        $completeProfileUrl .= (strpos($completeProfileUrl, '?') !== false ? '&' : '?') . 
                                             'redirect=' . urlencode($redirectPath);
                    }
                    return redirect()->to($completeProfileUrl);
                }

                // Redirect to intended path
                return redirect()->to(config('app.frontend_url').$redirectPath);
            }

            // For new social sign-ups, create the user with tracking data
            $newUser = User::create([
                'name' => $socialUser->name ?? $socialUser->nickname ?? 'User',
                'email' => $socialUser->email,
                'email_verified_at' => now(),
                'password' => Hash::make(Str::random(32)),
                'provider' => $provider->value,
                'preferred_language' => 'es',
                'registration_source' => $trackingData, // Store tracking data immediately
            ]);

            // Create Stripe customer if using Cashier
            if (method_exists($newUser, 'createAsStripeCustomer')) {
                try {
                    $newUser->createAsStripeCustomer([
                        'name' => $newUser->name,
                        'email' => $newUser->email,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to create Stripe customer for OAuth user: ' . $e->getMessage());
                }
            }

            Log::info('New social user created, pending profile completion', [
                'user_id' => $newUser->id,
                'email' => $newUser->email,
                'provider' => $provider->value,
                'has_tracking' => !empty($trackingData),
            ]);

            // Log the new user in
            Auth::login($newUser);

            // Redirect to complete profile page with tracking preserved
            $completeProfileUrl = config('app.frontend_url').'/app/account/complete-profile';
            if ($redirectPath && $redirectPath !== '/app') {
                $completeProfileUrl .= '?redirect=' . urlencode($redirectPath);
            }
            
            return redirect()->to($completeProfileUrl);

        } catch (Exception $e) {
            Log::error('Social login failed', [
                'provider' => $provider->value,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return redirect()->to(config('app.frontend_url').'/login?error=social_auth_failed');
        }
    }
}