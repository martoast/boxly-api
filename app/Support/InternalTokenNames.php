<?php

namespace App\Support;

use App\Http\Controllers\ExtensionTokenController;
use App\Http\Controllers\McpTokenController;

/**
 * The Sanctum token names Boxly issues for itself.
 *
 * Each of these is minted "one active per user" by deleting every prior token
 * of the same name first (see McpTokenController::issue / chatToken and
 * ExtensionTokenController::issue). That is fine for a connection Boxly owns —
 * but it means a *user-named* API key sharing one of these names would be
 * silently destroyed the next time the owner opened the AI card, the web chat,
 * or reconnected the Chrome extension.
 *
 * So this list does two jobs, and both matter:
 *   - api keys may not be created with these names (ApiKeyController::store)
 *   - these tokens are hidden from the api key list (ApiKeyController::index),
 *     because they are Boxly's plumbing, not keys the user issued
 *
 * The constants live on the controllers that own each flow; this class only
 * gathers them, so there is exactly one place a name is spelled.
 */
class InternalTokenNames
{
    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            McpTokenController::TOKEN_NAME,
            McpTokenController::CHAT_TOKEN_NAME,
            ExtensionTokenController::TOKEN_NAME,
        ];
    }

    public static function has(string $name): bool
    {
        return in_array($name, self::all(), true);
    }
}
