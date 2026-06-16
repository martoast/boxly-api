<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools;
use App\Mcp\Tools\Admin;
use Laravel\Mcp\Server;

/**
 * One MCP endpoint (POST /mcp/boxly), one connect flow. The tools exposed
 * depend on the connected user's role: customers manage their own account,
 * admins manage the business. Determined per-request in boot() (the route is
 * auth:sanctum, so request()->user() is set by the time boot runs).
 */
class BoxlyServer extends Server
{
    public string $serverName = 'Boxly';

    public string $serverVersion = '1.0.0';

    // Accept newer protocol versions modern MCP clients negotiate with.
    public array $supportedProtocolVersion = [
        '2025-11-25',
        '2025-06-18',
        '2025-03-26',
        '2024-11-05',
    ];

    public string $instructions = 'Boxly account assistant.';

    // Return the whole tool list in one page (we have ~19 admin tools).
    public int $defaultPaginationLength = 100;

    public int $maxPaginationLength = 200;

    public array $tools = [];

    private const CUSTOMER_TOOLS = [
        Tools\ListOrdersTool::class,
        Tools\GetOrderTool::class,
        Tools\TrackOrderTool::class,
        Tools\ListPurchaseRequestsTool::class,
        Tools\GetPurchaseRequestTool::class,
        Tools\GetProfileTool::class,
        Tools\CreateOrderTool::class,
        Tools\CreatePurchaseRequestTool::class,
        Tools\GetOrderPaymentLinkTool::class,
    ];

    private const ADMIN_TOOLS = [
        Admin\AdminDashboardTool::class,
        Admin\AdminListOrdersTool::class,
        Admin\AdminGetOrderTool::class,
        Admin\AdminCreateOrderTool::class,
        Admin\AdminUpdateOrderStatusTool::class,
        Admin\AdminListBoxesTool::class,
        Admin\AdminConsolidateOrderTool::class,
        Admin\AdminGenerateJesusMessageTool::class,
        Admin\AdminListCustomersTool::class,
        Admin\AdminGetCustomerTool::class,
        Admin\AdminCreateCustomerTool::class,
        Admin\AdminListPackagesTool::class,
        Admin\AdminListPurchaseRequestsTool::class,
        Admin\AdminGetPurchaseRequestTool::class,
        Admin\AdminQuotePurchaseRequestTool::class,
        Admin\AdminMarkPurchaseRequestPurchasedTool::class,
        Admin\AdminRejectPurchaseRequestTool::class,
        Admin\AdminListExpensesTool::class,
        Admin\AdminCreateExpenseTool::class,
        Admin\AdminListCampaignsTool::class,
        Admin\AdminCreateCampaignTool::class,
        Admin\AdminListShoppingTripsTool::class,
        Admin\AdminCreateShoppingTripTool::class,
    ];

    private const CUSTOMER_INSTRUCTIONS = <<<'TXT'
    You are connected to the user's Boxly account (US package forwarding +
    assisted purchasing for Mexico). Manage THEIR account: orders/packages,
    shipping quotes and tracking, purchase requests (ask Boxly to buy US
    products for them), and profile.

    Rules:
    - Confirm before creating an order or a purchase request.
    - You can never charge the user; get_order_payment_link only returns a
      Stripe URL for them to open themselves.
    - The Boxly US warehouse (casillero) address comes from get_profile.
    - Amounts are MXN unless noted (purchase-request item prices are USD).
    TXT;

    private const ADMIN_INSTRUCTIONS = <<<'TXT'
    You are connected to Boxly as an ADMIN. You can manage the whole business:
    dashboard/metrics, all orders (view + status), customers (view + create),
    warehouse packages, purchase requests (view, quote, mark purchased, reject),
    expenses, email campaigns (draft only — never auto-sent), and in-person
    shopping trips.

    Rules:
    - Confirm before any write that touches a customer: changing order status,
      quoting/marking/rejecting a purchase request, creating a customer, or
      creating a campaign.
    - Expense shorthand: "expense for Paco/Jesus for <date> for <amount>" =
      category shipping, description = the courier name. Amounts MXN.
    - Campaigns are created as drafts and are never sent automatically.
    TXT;

    public function boot(): void
    {
        $user = request()->user();
        $isAdmin = $user && method_exists($user, 'isAdmin') && $user->isAdmin();

        $this->instructions = $isAdmin ? self::ADMIN_INSTRUCTIONS : self::CUSTOMER_INSTRUCTIONS;

        foreach ($isAdmin ? self::ADMIN_TOOLS : self::CUSTOMER_TOOLS as $tool) {
            $this->addTool($tool);
        }
    }
}
