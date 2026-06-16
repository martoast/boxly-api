<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Self-issued Sanctum tokens for the Boxly MCP server.
 *
 * A logged-in customer visits the "Connect to your AI" section of their
 * account settings and clicks a button; that page (already authenticated via
 * Sanctum cookie session) calls this endpoint to mint a personal access token
 * they paste into their MCP client (Claude Code / Desktop). The token then
 * authenticates the MCP server's requests as that customer — and since the
 * customer API routes are auto-scoped to the authenticated user and admin
 * routes are middleware-gated, the token can only ever touch the customer's
 * own account.
 *
 * Mirrors ExtensionTokenController but with a distinct token name so the MCP
 * and Chrome-extension connections don't clobber each other. One active MCP
 * token per user: regenerating replaces the previous one.
 */
class McpTokenController extends Controller
{
    private const TOKEN_NAME = 'claude-mcp';

    public function issue(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        // Drop any prior MCP token this user has — only one active at a time.
        // Regenerating from a new client is a full reset.
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

    /**
     * Revoke the customer's active MCP token (disconnect).
     */
    public function revoke(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $deleted = $user->tokens()->where('name', self::TOKEN_NAME)->delete();

        return response()->json(['success' => true, 'revoked' => (bool) $deleted]);
    }
}
