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
            $socialUser = Socialite::driver($provider->value)->stateless()->user();
            
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

            $user = User::where('email', $socialUser->email)->first();

            if ($user) {
                if (!$user->provider) {
                    $user->update(['provider' => $provider->value]);
                }
                
                if (!$user->registration_source && $trackingData) {
                    $user->update(['registration_source' => $trackingData]);
                }

                Auth::login($user);

                // Only check phone requirement for profile completion
                if (!$user->phone) {
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

                return redirect()->to(config('app.frontend_url').$redirectPath);
            }

            // Create new user without user_type requirement
            $newUser = User::create([
                'name' => $socialUser->name ?? $socialUser->nickname ?? 'User',
                'email' => $socialUser->email,
                'email_verified_at' => now(),
                'password' => Hash::make(Str::random(32)),
                'provider' => $provider->value,
                'preferred_language' => 'es',
                'registration_source' => $trackingData,
                'user_type' => null, // No longer required
            ]);

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

            Auth::login($newUser);

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