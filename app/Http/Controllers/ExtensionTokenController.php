<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Self-issued Sanctum tokens for the Boxly Chrome extension.
 *
 * The extension's "Conectar con Boxly" button opens a tab on the web app.
 * That page (already authenticated via Sanctum cookie session) calls this
 * endpoint to mint a personal access token, then hands it off to the
 * extension via chrome.runtime.sendMessage. Velonie never has to copy/paste.
 *
 * One-token-per-user per name: re-issuing replaces the previous chrome
 * token so reconnecting from a different browser doesn't leave dangling
 * tokens that need manual revocation.
 */
class ExtensionTokenController extends Controller
{
    private const TOKEN_NAME = 'chrome-extension';

    public function issue(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        // Drop any prior chrome-extension tokens this user has — there should
        // only ever be one active at a time. New connect = full reset.
        $user->tokens()->where('name', self::TOKEN_NAME)->delete();

        $token = $user->createToken(self::TOKEN_NAME);

        return response()->json([
            'success' => true,
            'token'   => $token->plainTextToken,
            'api_url' => rtrim(config('app.api_url') ?: config('app.url'), '/'),
            'user'    => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->role,
                'team'  => $user->team,
            ],
        ]);
    }
}
