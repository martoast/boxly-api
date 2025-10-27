<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Jobs\SendFunnelCaptureWebhookJob;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());
            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        User::created(function (User $user) {
            // Create Stripe customer
            try {
                $user->createAsStripeCustomer([
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'metadata' => [
                        'user_type' => $user->user_type,
                        'registration_source' => $user->registration_source,
                    ]
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to create Stripe customer for user ' . $user->id . ': ' . $e->getMessage());
            }

            // Send webhook only if phone is set (profile is complete enough)
            if ($user->phone) {
                try {
                    SendFunnelCaptureWebhookJob::dispatch(
                        name: $user->name,
                        email: $user->email,
                        phone: $user->phone,
                        userType: $user->user_type, // Can be null
                        registrationSource: $user->registration_source
                    );
                    
                    Log::info('✅ Dispatched webhook for complete profile', [
                        'user_id' => $user->id,
                        'email' => $user->email,
                        'user_type' => $user->user_type ?? 'not set',
                        'trigger' => 'User::created',
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to dispatch webhook: ' . $e->getMessage());
                }
            } else {
                Log::info('⏸️ Skipped webhook - incomplete profile', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'has_phone' => !empty($user->phone),
                    'note' => 'Webhook will be sent after profile completion'
                ]);
            }
        });
    }
}