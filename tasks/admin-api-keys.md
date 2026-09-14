# Admin API keys — self-serve keys for the full admin surface

Plan written 2026-09-10. **Built 2026-09-10. Tested locally. Not deployed.**

## What already exists (verified in code — do NOT rebuild)

| Piece | Where | Status |
|---|---|---|
| Every authed route behind Sanctum | `routes/api.php:173` — `Route::middleware('auth:sanctum')` wraps 303 routes | ✅ |
| Admin surface inside it | `routes/api.php:335` — `->middleware('admin')->prefix('admin')`, **160 endpoints** | ✅ |
| Bearer tokens reach admin routes | `AdminMiddleware` only checks `$request->user()->isAdmin()` — guard-agnostic | ✅ |
| Proven in production | `cli/lib/client.js:103` sends `Authorization: Bearer <PAT>` against `/admin/*` | ✅ |
| Issue / list / revoke tokens | `Admin\AdminTokenController` (`/admin/users/{user}/cli-tokens`), already takes `abilities` | ✅ |
| A settings card that mints a token | `app` repo `components/AiConnectCard.vue`, mounted at `pages/app/admin/account/index.vue:84` | ✅ |

**The transport layer is done.** An admin PAT today can already do everything an
admin can do in the UI. What's missing is the product around it.

## The gaps (this is the actual work)

1. **No self-serve key management.** The only ways to mint are
   `POST /admin/users/{id}/cli-tokens` (shaped for issuing to *other* users) or
   the MCP card. There is no `/me/api-keys` and no UI to list or revoke.

2. **Name-keyed deletion is a live collision bug.** `McpTokenController` does
   `$user->tokens()->where('name', 'claude-mcp')->delete()` before issuing, and
   `chatToken()` does the same for `boxly-web-chat` (`ExtensionTokenController`
   likewise). If an admin names an API key one of those, the next visit to the
   AI card or the web chat **silently deletes their key.** Reserved names must
   be enforced, and those internal tokens hidden from the key list.

3. **Abilities are accepted but never enforced.** Nothing in the codebase calls
   `tokenCan()`. Every token is effectively `*`.

4. **No rate limit on authenticated traffic.** `throttle:` appears only on
   public routes (`routes/api.php:111-139`). A leaked key today is unmetered
   access to all 160 admin endpoints.

5. **Tokens never expire.** `config/sanctum.php:49` — `'expiration' => null`.

6. **No reference docs** for the admin surface.

## Recommendation — reuse Sanctum, do not build a parallel key system

`personal_access_tokens` already carries `name`, `abilities`, `last_used_at`,
`expires_at`. The CLI, MCP server and Chrome extension all already ride PATs. A
second `api_keys` table would mean two auth guards and two revocation paths for
one concept — the opposite of CLAUDE.md #6/#9. Everything below is additive.

**Decided by Alex 2026-09-10:**
- **Scopes: full-access (`*`) now, scopes later.** Gap #3 stays explicitly OUT of
  this pass — no half-built abilities field that looks like security but isn't.
- **Rate limiting: none for now.** Gap #4 stays OUT. Authed traffic remains
  unthrottled, exactly as today; the leaked-key exposure is accepted knowingly.

## Todo

### API repo
- [x] 1. `Me\ApiKeyController` — `GET/POST/DELETE /me/api-keys` under
      `auth:sanctum`. Admin-gated for this first cut (Alex: "start with the
      admin account first"). Plaintext returned once on create, never again.
- [x] 2. Reserved-name guard: one shared const listing `claude-mcp`,
      `boxly-web-chat`, and the extension token name. Reject on create, and
      filter them out of `index()` so internal tokens aren't shown as API keys.
      Fixes gap #2.
- [x] 3. Optional `expires_at` on create (Sanctum 4 supports per-token expiry;
      default null = never, matching today's behaviour).
- [ ] ~~4. Per-token rate limit~~ — **dropped by Alex, out of this pass.**
- [x] 5. Token prefix (`config/sanctum.php` `token_prefix`) e.g. `bx_` so a
      leaked key is greppable and scannable by secret scanners.
- [x] 6. Feature tests: admin key reaches `/admin/*`; customer key gets 403;
      revoked key gets 401; reserved name rejected; the MCP card does not
      delete a user key (regression test for gap #2).
- [x] 7. `docs/` reference for the admin surface + a short auth guide.

### App repo
- [x] 8. `components/ApiKeysCard.vue` — create (name + optional expiry), list
      with `last_used_at`, revoke, one-time reveal with copy. Mirrors the
      existing `AiConnectCard` styling.
- [x] 9. Mount it on `pages/app/admin/account/index.vue` beside `AiConnectCard`.

## Deploy note
`api/` → DigitalOcean on push to `main`, several minutes, migrations automatic.
`app/` → Netlify, fast. Probe the route for a 401 before believing it is live
(api/CLAUDE.md "Verify it actually landed").

## Review
_(to be filled in when the work is done — CLAUDE.md #7)_
