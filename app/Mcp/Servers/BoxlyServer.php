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
        Admin\AdminListDropOffReceiptsTool::class,
        Admin\AdminGetDropOffReceiptTool::class,
        Admin\AdminCreateDropOffReceiptTool::class,
        Admin\AdminSendDropOffReceiptTool::class,
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
    - Product discovery and verification happen through the authenticated live-shopping
      computer-use pipeline, not through direct provider searches or page scraping.
    - Amounts are MXN unless noted (purchase-request item prices are USD).
    TXT;

    private const ADMIN_INSTRUCTIONS = <<<'TXT'
    You are connected to Boxly as an ADMIN. You can manage the whole business:
    dashboard/metrics, all orders (view + status), customers (view + create),
    warehouse packages, purchase requests (view, quote, mark purchased, reject),
    expenses, email campaigns (draft only — never auto-sent), in-person
    shopping trips, and drop-off receipts.

    Rules:
    - Confirm before any write that touches a customer: changing order status,
      quoting/marking/rejecting a purchase request, creating a customer, or
      creating a campaign.
    - Expenses have a scope: "business" (default — company costs, feeds profit)
      or "personal" (the owners' own money, tracked separately, never in profit).
      Business shorthand: "expense for Paco/Jesus for <date> for <amount>" =
      scope business, category shipping, description = the courier name.
      Personal: "personal rent/food/misc <amount>" = scope personal + that
      category. Amounts MXN. Don't ask clarifying questions, just log it.
    - Campaigns are created as drafts and are never sent automatically.
    - A drop-off receipt records a customer physically handing packages over.
      Creating one never emails anybody; admin_send_drop_off_receipt does, so
      confirm before calling it, and only after any photos are attached (photos
      are uploaded from the admin panel, not over MCP).
    - Product discovery and verification happen through the authenticated live-shopping
      computer-use pipeline, not through direct provider searches or page scraping.
    TXT;

    public function boot(): void
    {
        $user = request()->user();
        $isAdmin = $user && method_exists($user, 'isAdmin') && $user->isAdmin();

        $this->instructions = $isAdmin ? self::ADMIN_INSTRUCTIONS : self::CUSTOMER_INSTRUCTIONS;

        $tools = array_merge(
            $isAdmin ? self::ADMIN_TOOLS : self::CUSTOMER_TOOLS,
        );
        foreach ($tools as $tool) {
            $this->addTool($tool);
        }
    }
}
