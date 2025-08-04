<?php

namespace App\Http\Controllers\Auth;

use App\Enums\SocialProviderEnum;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;

final class AuthSocialRedirectController extends Controller
{
    public function __invoke(SocialProviderEnum $provider, Request $request): RedirectResponse
    {
        // Get the state parameter from the request (contains tracking data)
        $state = $request->get('state');
        
        // Pass it through to the OAuth provider
        return Socialite::driver($provider->value)
            ->stateless()
            ->with(['state' => $state])
            ->redirect();
    }
}