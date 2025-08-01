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
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
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

        // Create Stripe customer after user registration
        User::created(function (User $user) {
            try {
                $user->createAsStripeCustomer([
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to create Stripe customer for user ' . $user->id . ': ' . $e->getMessage());
            }

            try {
                SendFunnelCaptureWebhookJob::dispatch(
                    $user->name,
                    $user->email,
                    $user->phone ?? ''
                );
                
                Log::info('Dispatched GoHighLevel webhook job for new user registration', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to dispatch GoHighLevel webhook job for user ' . $user->id . ': ' . $e->getMessage());
            }
        });
    }
}