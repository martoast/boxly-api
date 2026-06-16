<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CreateOrderTool;
use App\Mcp\Tools\CreatePurchaseRequestTool;
use App\Mcp\Tools\GetOrderPaymentLinkTool;
use App\Mcp\Tools\GetOrderTool;
use App\Mcp\Tools\GetProfileTool;
use App\Mcp\Tools\GetPurchaseRequestTool;
use App\Mcp\Tools\ListOrdersTool;
use App\Mcp\Tools\ListPurchaseRequestsTool;
use App\Mcp\Tools\TrackOrderTool;
use Laravel\Mcp\Server;

class BoxlyServer extends Server
{
    public string $serverName = 'Boxly';

    public string $serverVersion = '1.0.0';

    // Accept newer protocol versions modern MCP clients negotiate with, on top
    // of what the package ships. Our tool surface is version-agnostic.
    public array $supportedProtocolVersion = [
        '2025-11-25',
        '2025-06-18',
        '2025-03-26',
        '2024-11-05',
    ];

    public string $instructions = <<<'TXT'
    You are connected to the user's Boxly account (US package forwarding +
    assisted purchasing for Mexico). Use these tools to read and manage THEIR
    account: orders/packages, shipping quotes and tracking, purchase requests
    (ask Boxly to buy US products for them), and profile.

    Rules:
    - Confirm with the user before creating an order or a purchase request.
    - You can never charge the user. get_order_payment_link only returns a
      Stripe URL for them to open themselves.
    - The Boxly US warehouse (casillero) address comes from get_profile — that's
      the address they use when shopping US stores.
    - Amounts are MXN unless noted (purchase-request item prices are USD).
    TXT;

    public array $tools = [
        ListOrdersTool::class,
        GetOrderTool::class,
        TrackOrderTool::class,
        ListPurchaseRequestsTool::class,
        GetPurchaseRequestTool::class,
        GetProfileTool::class,
        CreateOrderTool::class,
        CreatePurchaseRequestTool::class,
        GetOrderPaymentLinkTool::class,
    ];
}
