<?php

namespace App\Http\Controllers\Auth;

use App\Enums\SocialProviderEnum;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Facades\Socialite;

final class AuthSocialRedirectController extends Controller
{
    public function __invoke(SocialProviderEnum $provider): RedirectResponse
    {
        return Socialite::driver($provider->value)->stateless()->redirect();
    }
}