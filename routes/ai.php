<?php

use App\Mcp\Servers\BoxlyServer;
use Laravel\Mcp\Server\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP Servers
|--------------------------------------------------------------------------
| Loaded by laravel/mcp under the "mcp" prefix. The Boxly customer server is
| available at POST /mcp/boxly and authenticated with the customer's personal
| access token (the same one minted on the web app's "Connect your AI" page).
| Sanctum scopes everything to that customer; admin routes stay gated.
*/

Mcp::web('boxly', BoxlyServer::class)
    ->middleware('auth:sanctum');
