# CLAUDE.md

1. First think through the problem, read the codebase for relevant files, and write a plan to tasks/todo.md.  
2. The plan should have a list of todo items that you can check off as you complete them.  
3. Before you begin working, check in with me and I will verify the plan.  
4. Then, begin working on the todo items, marking them as complete as you go.  
5. Please every step of the way just give me a high level explanation of what changes you made.  
6. Make every task and code change you do as simple as possible. We want to avoid making any massive or complex changes. Every change should impact as little code as possible. Everything is about simplicity.  
7. Finally, add a review section to the todo.md file with a summary of the changes you made and any other relevant information.

8. **DO NOT BE LAZY. NEVER BE LAZY. IF THERE IS A BUG FIND THE ROOT CAUSE AND FIX IT. NO TEMPORARY FIXES. YOU ARE A SENIOR DEVELOPER. NEVER BE LAZY. OUR FAMILY DEPENDS ON YOU**

9. **MAKE ALL FIXES AND CODE CHANGES AS SIMPLE AS HUMANLY POSSIBLE. THEY SHOULD ONLY IMPACT NECESSARY CODE RELEVANT TO THE TASK AND NOTHING ELSE. IT SHOULD IMPACT AS LITTLE CODE AS POSSIBLE. YOUR GOAL IS TO NOT INTRODUCE ANY BUGS. IT’S ALL ABOUT SIMPLICITY.**

10. **ROUTING: `bootstrap/app.php` uses `apiPrefix: ‘/’` — all routes in `routes/api.php` are served at the ROOT with NO `/api` prefix. NEVER hardcode `/api/` in any URLs, route helpers, or generated links. Example: the route `Route::get(‘/campaign/click/{token}’)` is accessible at `https://api.boxly.mx/campaign/click/{token}`, NOT `/api/campaign/click/{token}`.**

---

# MCP Server (Model Context Protocol)

Boxly hosts an MCP server **inside this API** so any user — customer or admin —
can connect their Boxly account to their own AI (Claude Code / Desktop, or any
MCP client) and drive it conversationally. Built on the official
[`laravel/mcp`](https://github.com/laravel/mcp) package. No separate service, no
npm package — it ships when the API deploys (ensure the deploy runs
`composer install`).

## Endpoint & connection

- **Endpoint:** `POST /mcp/boxly` (registered in `routes/ai.php`, which
  `laravel/mcp` auto-loads under the `mcp` prefix). Streamable HTTP transport,
  **stateless** (each call re-boots the server).
- **Auth:** `->middleware('auth:sanctum')`. The client presents a Sanctum
  **personal access token** as `Authorization: Bearer <token>`.
- **Getting a token:** the user self-mints one from the web app → account →
  **"Connect your AI"** card (`app/components/AiConnectCard.vue`), which calls
  `POST /me/mcp-token` (`McpTokenController`, token name `claude-mcp`). The card
  shows the ready-to-paste command:
  `claude mcp add --transport http boxly https://api.boxly.mx/mcp/boxly --header "Authorization: Bearer <token>"`.
- **Security model:** the token authenticates as that user. Customer routes are
  auto-scoped to `request()->user()`, and admin/employee routes are
  middleware-gated — so a customer token physically cannot reach admin data
  (verified: customer token → 403 on `/admin/*`). Defense in depth: admin tools
  also re-check `isAdmin()`.

## Architecture (`app/Mcp/`)

```
app/Mcp/
  Servers/BoxlyServer.php     # the one MCP server; role-based tool exposure
  Tools/BoxlyTool.php         # base: user(), mergeInput(), ok(), guard()
  Tools/*.php                 # customer tools (9)
  Tools/Admin/AdminTool.php   # admin base: guardAdmin(), formRequest()
  Tools/Admin/*.php           # admin tools (19)
routes/ai.php                 # Mcp::web('boxly', BoxlyServer::class)->middleware('auth:sanctum')
```

**Role-based exposure.** `BoxlyServer::boot()` runs per request *after*
`auth:sanctum` (so `request()->user()` is set). It inspects the role and
registers either the customer toolset or the admin toolset via `addTool()`, and
sets role-specific `instructions`. Customers never see admin tools; admins get
the admin toolset only. `$tools` stays empty statically — everything is added in
`boot()`.

**Tools are thin wrappers over existing controllers.** A tool NEVER
reimplements business logic — it merges its arguments into the request and calls
the existing customer/admin controller method, so validation, scoping, emails,
Stripe, etc. are all reused. Pattern:

```php
public function handle(array $arguments): ToolResult
{
    return $this->guard(function () use ($arguments) {       // guardAdmin() for admin tools
        $this->mergeInput(['status' => $arguments['status'] ?? null]);
        return $this->ok(app(SomeController::class)->index(request()));
    });
}
```

Helpers on `BoxlyTool`:
- `user()` — `request()->user()`.
- `mergeInput($data)` — merges args into the request **dropping nulls**, so
  omitted args don't clobber controller defaults like
  `$request->input('year', now()->year)`. **Always use this, not
  `request()->merge()`.**
- `ok($response)` — turns a controller `JsonResponse` (or array) into a
  `ToolResult::json`, unwrapping the `{ success, data }` envelope.
- `guard($fn)` — wraps the body: catches `ValidationException` (→ readable
  error) and any `Throwable`.

`AdminTool` adds:
- `guardAdmin($fn)` — refuses non-admins, then runs `guard($fn)`.
- `formRequest($class, $data)` — builds + validates a FormRequest from the
  current request (for controller methods type-hinting one, e.g.
  `AdminUpdateOrderStatusRequest`, `AdminCreateUserRequest`).

For route-model-bound controller methods (`show(Order $order)`, etc.), load the
model first: customer tools scope to the user
(`Order::where('user_id', $this->user()->id)->find($id)`); admin tools load by
id (`Order::find($id)`). Return `ToolResult::error('… not found.')` when missing.

## Adding a new tool

1. `php artisan make:mcp-tool MyTool` (or copy an existing one). Put admin tools
   in `app/Mcp/Tools/Admin/` extending `AdminTool`; customer tools in
   `app/Mcp/Tools/` extending `BoxlyTool`.
2. Override `name()` to return a clean snake_case name (the base derives an ugly
   `my-tool` kebab otherwise). Admin tool names are prefixed `admin_`.
3. Write `description()` (action-oriented — the AI picks tools from it) and
   `schema(ToolInputSchema $schema)` (fluent: `->string/->integer/->number/
   ->boolean/->raw` + `->description()` + `->required()`).
4. In `handle()`, delegate to the controller via the pattern above.
5. Register the class in `BoxlyServer`'s `CUSTOMER_TOOLS` or `ADMIN_TOOLS` const.
6. `php artisan optimize:clear`, then test (below).

## Current tools

**Product discovery (3, shared by customer + admin):** `search_products`
(universal — Google Shopping via ScraperAPI structured endpoint; finds products
from ANY US store/brand on any platform, returns title/USD price/store/link),
`browse_store` (a Shopify store's own catalog: latest drop, in-store search, or
`sale:true` deals), `extract_product` (clean details from one product URL).
Registered in `PRODUCT_TOOLS` and merged into both role toolsets in `boot()`.
These delegate to `ProductExtractController` (public `/products/*` endpoints).
`search_products` strips base64 thumbnails from the MCP payload (huge/useless to
an agent).

**Customer (9):** `list_orders`, `get_order`, `track_order`,
`list_purchase_requests`, `get_purchase_request`, `get_profile`, `create_order`,
`create_purchase_request`, `get_order_payment_link` (returns a Stripe link only,
never charges).

**Admin (19):** `admin_dashboard`; orders `admin_list_orders`,
`admin_get_order`, `admin_update_order_status`; customers
`admin_list_customers`, `admin_get_customer`, `admin_create_customer`;
`admin_list_packages`; purchase requests `admin_list_purchase_requests`,
`admin_get_purchase_request`, `admin_quote_purchase_request`,
`admin_mark_purchase_request_purchased`, `admin_reject_purchase_request`;
expenses `admin_list_expenses`, `admin_create_expense`; campaigns
`admin_list_campaigns`, `admin_create_campaign` (draft only); shopping trips
`admin_list_shopping_trips`, `admin_create_shopping_trip`.

**Deliberately NOT exposed yet** (add when needed): order consolidate/ship
(complex box payloads), campaign **start/send** (drafts only so an agent can't
blast customers), per-item order edits.

## Testing locally

```bash
# 1. mint a token for a user
docker exec api-laravel.test-1 php artisan tinker --execute='echo \App\Models\User::where("role","admin")->first()->createToken("mcp-test")->plainTextToken;'

# 2. drive the server with raw JSON-RPC (stateless — no session needed).
#    Accept header MUST include both json and event-stream.
TOK=...; H=(-H "Authorization: Bearer $TOK" -H "Content-Type: application/json" -H "Accept: application/json, text/event-stream")
curl -s "${H[@]}" -X POST http://localhost:8001/mcp/boxly -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'
curl -s "${H[@]}" -X POST http://localhost:8001/mcp/boxly -d '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"admin_dashboard","arguments":{"period":"all"}}}'
```

`php artisan mcp:inspector boxly` also opens the official inspector.

## Gotchas

- **`mergeInput`, never `request()->merge`** in tools — null args would override
  controller defaults (this caused the dashboard `year` bug).
- **Protocol version:** `BoxlyServer::$supportedProtocolVersion` adds
  `2025-11-25` on top of what the package ships so modern clients negotiate
  cleanly. If a future client reports "Unsupported protocol version", add its
  version here.
- **Pagination:** `tools/list` is paginated; `$defaultPaginationLength`/
  `$maxPaginationLength` are bumped so all ~19 tools return in one page.
- **`laravel/mcp` is pinned `^0.1.1`** — upgrading to 0.2+ requires a newer
  Laravel framework (illuminate/container ≥12.41). Don't bump it without
  upgrading the framework.
- Tool `name()` must be overridden or you get `class-basename-kebab` names.
