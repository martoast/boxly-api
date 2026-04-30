<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Issue / list / revoke Sanctum personal access tokens for any user.
 * Used by the CLI for service-style auth — see cli/lib/client.js.
 *
 * Plaintext token is only returned once at issue time. After that, only
 * the metadata (id, name, last_used_at) is queryable.
 */
class AdminTokenController extends Controller
{
    public function index(User $user)
    {
        $tokens = $user->tokens()
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'last_used_at', 'created_at', 'expires_at']);

        return response()->json([
            'success' => true,
            'data'    => $tokens,
        ]);
    }

    public function store(Request $request, User $user)
    {
        $validated = $request->validate([
            'name'      => 'nullable|string|max:100',
            'abilities' => 'nullable|array',
            'abilities.*' => 'string|max:50',
        ]);

        $name = $validated['name'] ?? 'cli';
        $abilities = $validated['abilities'] ?? ['*'];

        $token = $user->createToken($name, $abilities);

        Log::info('Issued Sanctum token', [
            'admin_id' => $request->user()->id,
            'user_id'  => $user->id,
            'name'     => $name,
            'token_id' => $token->accessToken->id,
        ]);

        // plainTextToken is the only chance to read the secret — return + log nothing else.
        return response()->json([
            'success' => true,
            'message' => 'Token created. Save it now — it will not be shown again.',
            'data' => [
                'id'         => $token->accessToken->id,
                'name'       => $token->accessToken->name,
                'token'      => $token->plainTextToken,
                'created_at' => $token->accessToken->created_at,
            ],
        ], 201);
    }

    public function destroy(Request $request, User $user, int $tokenId)
    {
        $deleted = $user->tokens()->where('id', $tokenId)->delete();

        if (! $deleted) {
            return response()->json([
                'success' => false,
                'message' => 'Token not found for this user.',
            ], 404);
        }

        Log::info('Revoked Sanctum token', [
            'admin_id' => $request->user()->id,
            'user_id'  => $user->id,
            'token_id' => $tokenId,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Token revoked.',
        ]);
    }
}
