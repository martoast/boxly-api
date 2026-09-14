# Boxly API keys

Programmatic access to the Boxly API using a key you issue yourself from the
web app. **Admin accounts only** for now.

## What a key is

A Boxly API key **is** a Sanctum personal access token — the same credential the
admin CLI and the MCP server already use. Nothing separate authenticates it.

That matters for reasoning about access: every authenticated route in
`routes/api.php` is already behind `auth:sanctum`, and the admin surface is
`auth:sanctum` + the `admin` middleware on top. So **a key reaches exactly what
its owner reaches in the web app** — no more, and nothing has to be re-exposed
endpoint by endpoint.

Keys are **full-access**. Per-key scopes are deliberately not implemented: an
`abilities` field that nothing enforces would look like security without being
it. Adding them means a middleware that calls `tokenCan()` per route group.

## Getting a key

Web app → **Account** → **API keys**. Give it a name, optionally an expiry,
create. The secret is shown **once** — it is stored hashed and cannot be
recovered. Lose it, revoke it and make another.

Reserved names: `claude-mcp`, `boxly-web-chat`, `chrome-extension`. Boxly issues
those for its own connections by deleting every token of that name first, so a
key sharing one would be destroyed the next time you opened the AI card or the
web chat. The API rejects them with a 422 (see `App\Support\InternalTokenNames`).

## Using a key

Send it as a bearer token. **Always send `Accept: application/json`.**

```bash
curl https://api.boxly.mx/admin/orders \
  -H "Authorization: Bearer <your-key>" \
  -H "Accept: application/json"
```

**There is no `/api` prefix.** `bootstrap/app.php` sets `apiPrefix: '/'`, so
routes live at the root: `https://api.boxly.mx/admin/orders`, not `/api/admin/...`.

### Responses

Controllers return a `{ success, data }` envelope; `success: false` carries a
`message`. Validation failures are standard Laravel 422s with `errors`.

### Status codes worth knowing

| Code | Means |
|---|---|
| 401 | Key missing, revoked, or expired |
| 403 | Authenticated but not an admin |
| 404 | Route exists but the record doesn't — or the deploy hasn't landed |
| 422 | Validation failed (`errors` names the fields) |

## Managing keys over the API

| Method | Route | Does |
|---|---|---|
| `GET` | `/me/api-keys` | List your keys (never the secrets, never Boxly's own connection tokens) |
| `POST` | `/me/api-keys` | Create one. Body: `name` (required), `expires_in_days` (optional, 1–3650) |
| `DELETE` | `/me/api-keys/{id}` | Revoke one. Effective immediately |

## The surface

All **160** admin endpoints are available. `php artisan route:list --path=admin`
is the authoritative list. The main groups:

`affiliates` · `ai-search` · `boxes` · `campaigns` · `categories` ·
`customers` · `dashboard` · `drop-off-receipts` · `expenses` · `knowledge` ·
`management` · `operations-board` · `order-events` · `orders` · `packages` ·
`purchase-requests` · `shopper-extension` · `shopping-trips` ·
`starter-prompts` · `stores` · `stripe` · `users` · `war-chest`

## Security notes

- A key is a **full admin credential**. Never commit one; never put one in
  front-end code.
- **Authenticated traffic is currently unthrottled** — `throttle:` is applied
  only to public routes. A leaked key is unmetered until revoked, so revoke
  promptly and prefer an expiry.
- Optional hardening, no code change: set `SANCTUM_TOKEN_PREFIX=bx_` in the API
  environment so new keys carry a greppable prefix that secret scanners can
  recognise (`config/sanctum.php` already reads this env var).
- `last_used_at` on the key list is the cheapest way to spot a key still in use
  — or one nobody has touched in months and should be revoked.
