<?php

namespace App\Http\Controllers;

use App\Support\InternalTokenNames;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Self-serve API keys for the signed-in user.
 *
 * A Boxly API key IS a Sanctum personal access token — the same credential the
 * CLI and the MCP server already use. Nothing new authenticates it: every
 * authenticated route is already behind `auth:sanctum` (routes/api.php), and
 * the admin surface is `auth:sanctum` + `admin` on top. So a key issued here
 * reaches exactly what its owner reaches in the web app, no more.
 *
 * Keys are full-access (`*`) by design: the point is "do everything an admin
 * can do in the interface". Per-key scopes are deliberately NOT implemented —
 * an `abilities` field that nothing enforces would look like security without
 * being it. When scopes arrive they need a middleware calling tokenCan().
 *
 * Admin-only for now (see routes/api.php) — customer-facing keys are a later
 * pass and want their own rate limiting and docs first.
 */
class ApiKeyController extends Controller
{
    /** Keys the user has issued — never Boxly's own connection tokens. */
    public function index(Request $request)
    {
        $keys = $request->user()->tokens()
            ->whereNotIn('name', InternalTokenNames::all())
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'last_used_at', 'expires_at', 'created_at']);

        return response()->json(['success' => true, 'data' => $keys]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                // Boxly mints these for itself by deleting every token of the
                // same name first, so a user key sharing one would vanish the
                // next time they opened the AI card. See InternalTokenNames.
                Rule::notIn(InternalTokenNames::all()),
            ],
            // Optional lifetime in days. Omitted = never expires, matching
            // every token Boxly issues today (config/sanctum.php expiration).
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ], [
            'name.not_in' => 'That name is reserved by Boxly. Please choose another.',
        ]);

        $user = $request->user();
        $expiresAt = isset($validated['expires_in_days'])
            ? now()->addDays($validated['expires_in_days'])
            : null;

        $token = $user->createToken($validated['name'], ['*'], $expiresAt);

        Log::info('Issued API key', [
            'user_id'  => $user->id,
            'name'     => $validated['name'],
            'token_id' => $token->accessToken->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Key created. Copy it now — it will not be shown again.',
            'data' => [
                'id'         => $token->accessToken->id,
                'name'       => $token->accessToken->name,
                // The one and only time the secret is readable.
                'key'        => $token->plainTextToken,
                'expires_at' => $token->accessToken->expires_at,
                'created_at' => $token->accessToken->created_at,
            ],
        ], 201);
    }

    public function destroy(Request $request, int $id)
    {
        $user = $request->user();

        // Scoped to the caller's own tokens, and never Boxly's connection
        // tokens — those are disconnected from their own cards, not from here.
        $deleted = $user->tokens()
            ->whereNotIn('name', InternalTokenNames::all())
            ->where('id', $id)
            ->delete();

        if (! $deleted) {
            return response()->json([
                'success' => false,
                'message' => 'Key not found.',
            ], 404);
        }

        Log::info('Revoked API key', ['user_id' => $user->id, 'token_id' => $id]);

        return response()->json(['success' => true, 'message' => 'Key revoked.']);
    }
}
