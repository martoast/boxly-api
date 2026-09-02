# Live Shopping — P1 integration slice (API side)

Plan only. **Nothing here is implemented.** Per CLAUDE.md rule 3 this needs
Alex's verification before any code is written.

Source of truth for the architecture:
`/home/alex/mcp-servers/computer-use/docs/REMOTE_SHOPPING_PLATFORM_PLAN.md`
§3 (topology), §13 (P1 slice definition). This file is the API-side half of
that slice and nothing more.

**Scope discipline.** P1 is *one conversation-attached live session, one
store, view-only*. Laravel is the CONTROL PLANE only — it never proxies,
terminates or even sees video. Everything below is a thin owner-scoped
wrapper over an engine that already exists; no business logic moves into
this repo.

## Why this file is not `tasks/todo.md`

`tasks/todo.md` currently holds the **in-flight** "Store Checkout → PR Review
Flow Migration" plan with unchecked boxes and confirmed-with-Alex decisions.
Overwriting it would destroy a live checklist, so this plan lives beside it —
the repo already keeps several named plan files (`frontend-todo.md`,
`war-chest-pure-crud.md`, …). It moves into `todo.md` once the checkout plan
closes.

---

## Decisions — resolved with safe defaults

These were open questions in the first draft. They now have defaults chosen so
implementation is never blocked; Alex can override any of them.

- [x] **Feature stays OFF until configured.** `enabled` defaults false, and is
      treated as false unless `base_url` AND `service_secret` are both present.
      A half-configured deployment behaves exactly like a disabled one.
- [x] **The webhook secret is a DISTINCT secret** from the service secret. They
      protect opposite directions; sharing one means a leaked outbound
      credential can forge inbound results.
- [x] **One active session per user, enforced at the database.** A precheck
      (`if (active exists) refuse`) is a race: two requests both read "none"
      and both insert. See §2 for the atomic constraint. Violation → **409**.

Still genuinely Alex's: the engine base URL / signaling hostname, and the two
secret values.

---

## Backend (`/home/alex/boxly-api`) — todo

### 1. Cross-repo contract — **frozen EngineV1**

Every wire shape below is EngineV1 as frozen by codex-main (message 5b0cb407).
The older `{objective, store}` / `{event_id, status, products}` shapes are gone
from this file; anything resembling them is a bug, not a variant.

**No callback URL crosses this boundary.** The engine's deployment config maps
the fixed id `boxly-p1` to the canonical public HTTPS `/live-shopping/webhook`
destination and validates readiness. Laravel neither constructs nor transmits a
callback URL.

**Envelopes differ by direction, deliberately.** The three customer routes keep
Boxly's own `{ success, data }`. Everything on the engine boundary — outbound
bodies, inbound bodies, and our webhook ack — uses EngineV1's
`{ ok, data, schema_version }`. Do not "harmonize" these; they are two different
contracts that happen to meet in one controller.

**`schema_version: 1`** is sent on every outbound body and required on every
inbound one. A body without it, or with a version this code does not know, is
rejected — not best-effort parsed.

---

#### 1.1 `POST /live-shopping/sessions` (Sanctum, owner = caller)

```jsonc
// request
{
  "conversation_id": 123,   // REQUIRED int, MUST belong to the caller
  "objective": "…",         // required string ≤500 (maps to engine `query`)
  "store_id": "on"          // required slug, ^[a-z0-9][a-z0-9_-]{0,39}$
}
// 201 response  (Boxly envelope)
{ "success": true, "data": {
  "id": 9, "status": "running", "engine_session_id": "eng_…",
  "conversation_id": 123, "store_id": "on",
  "expires_at": "2026-09-01T12:34:56Z",
  "created_at": "…", "updated_at": "…" } }
```

- **TWO ID DOMAINS, never interchangeable — cross-repo freeze.** The create
  response carries both, and the frontend's transient tool handle must keep them
  as two explicitly named fields (`localSessionId` / `engineSessionId`), never
  one "session id":
  - `id` — the **Laravel model id**. The only id valid in a Laravel route
    (`/live-shopping/sessions/{id}`, `…/ticket`).
  - `engine_session_id` — the **engine's** id. Used only to validate that an
    inbound `EventV1.session_id` belongs to this session.

  Confusing them fails in two directions and neither is loud: the local id sent
  to the engine 404s, and the engine id accepted as a local id means one
  customer's stream validates against another's session. `PublicContractTest`
  locks the exact key set of all three public envelopes so Nuxt fixtures cannot
  drift without a failure on this side.
- **`conversation_id` is now REQUIRED** (was optional). EngineV1 is
  conversation-attached end to end — the create body carries it, the terminal
  delivery echoes it, and terminal persistence is an append into that thread. A
  session with no conversation has nowhere to land its result.
- **`store.origin` is gone entirely.** The engine owns the catalog and the
  origin. Laravel neither accepts nor forwards an origin, which deletes the
  SSRF-hygiene surface the old plan had to carry (see §6).
- **`store_id` is flat, not nested — FROZEN.** The customer request is exactly
  `{conversation_id, objective, store_id}`. Nuxt is notified through the shared
  contract; this shape is no longer an open question on either side. Confirm before either side codes it.

#### 1.2 Laravel → engine `POST {base_url}/v1/sessions`

Signed with the **service-plane HMAC of §1.6** — *not* a bearer token. Plus
`Idempotency-Key: live-shopping-session-{local row id}`.

```jsonc
{
  "schema_version": 1,
  "conversation_id": "123",   // String(local conversation id)
  "store_id": "on",           // String(store_id)
  "query": "…",               // the caller's `objective`
  "callback_id": "boxly-p1"
}
```

Note the two renames across the boundary: our `objective` → engine `query`, and
`conversation_id` is **stringified**. Both are one-line mappings in the client;
neither leaks into our own API or our schema.

Response (wrapped):

```jsonc
{ "ok": true, "data": { "schema_version": 1, "session": {
    "id": "eng_…", "conversation_id": "123", "store_id": "on",
    "status": "running", "latest_seq": 0,
    "created_at": "…", "expires_at": "2026-09-01T12:34:56Z" } } }
```

- `session.id` → our `engine_session_id`. `expires_at` persisted (§2).
- `status` is `running` only after **durable acceptance** — the engine has
  committed the session, not merely parsed the request.
- **Correlation is validated, not assumed.** `data.session.conversation_id` must
  equal the local conversation id and `data.session.store_id` must equal the
  requested store id. A mismatch means we are about to attach another session's
  stream to this customer's thread, so it is a failed create (§11) — not a
  warning, and not something the expiry check would ever catch. Checking only
  `status`/`expires_at` is exactly the hole this clause closes.
- `latest_seq` is persisted as the sequence floor for §7's bound check.
- Missing/unparseable `expires_at`, `ok:false`, or an unknown `schema_version`
  → failed create. A session with no deadline holds the user's active slot
  forever.

#### 1.3 `POST /live-shopping/sessions/{id}/ticket`

**POST, not GET — frozen.** Minting a ticket is a state-changing engine
round-trip that issues a short-lived media credential. A GET would be cacheable,
prefetchable, and replayable from a link; none of those are acceptable for a
credential. Throttled (§5).

Boxly envelope out: `{ success, data: { ticket, expires_at, sse_url, whep_url,
ice_servers } }`, `expires_at` ≤60s out. An engine round-trip on **every**
request — never a locally minted token.

**Laravel → engine `POST {base_url}/v1/sessions/{engine_session_id}/viewer-tickets`**
— signed with the service-plane HMAC of §1.6, `{engine_session_id}` percent-
encoded into the path (it is engine-supplied data landing in a URL). Body:

```jsonc
{ "schema_version": 1,
  "user_id": "42",                              // String(auth user id)
  "scopes": ["events:read", "media:read"] }     // P1 is view-only
```

Response (wrapped):

```jsonc
{ "ok": true, "data": { "schema_version": 1, "ticket": "…",
    "expires_at": "…", "sse_url": "…", "whep_url": "…", "ice_servers": [ … ] } }
```

- The request body is **not empty** — the engine scopes the ticket to a user and
  to read-only capabilities. `scopes` is the constant pair above in P1; anything
  broader is P3's instruction channel, not this slice.
- Laravel passes through **only the five inner public fields** and persists none
  of them. The `ok`/`schema_version` wrapper is unwrapped, never forwarded.
- `expires_at` >60s out is treated as an invalid response, not clamped — a
  long-lived viewer ticket is the engine misbehaving, and silently shortening it
  hides that.
- **Laravel never invents media or TURN details.** It does not know an SFU URL
  and cannot mint a TURN credential; a fabricated one produces a viewer that
  connects to nothing and looks, to the customer, exactly like a working
  session. Unreachable, timeout, non-2xx, `ok:false`, or any of the five fields
  missing → honest **503** (§11), never a partial ticket.

#### 1.4 Terminal webhook `POST /live-shopping/webhook` (engine → Laravel)

```jsonc
{
  "schema_version": 1,
  "delivery_id": "dlv_…",        // UNIQUE per delivery — the idempotency key
  "session_id": "eng_…",         // engine_session_id
  "terminal_seq": 42,            // recorded and bounded, §7
  "conversation_id": "123",      // correlated against the local row, §7
  "occurred_at": "…",
  "result": {
    "outcome": "completed",      // completed | failed | cancelled
    "products": [ /* ProductV1[] */ ],
    "error_code": null           // string when outcome=failed
  },
  "assistant_part": {
    "type": "tool-live_results",
    "state": "output-available",
    "output": { "products": [ /* ProductV1[] */ ] }
  }
}
```

**ProductV1** — `{ store, store_id, title, url, image, current_price,
list_price, availability, observed_at }`. This is the engine's schema. It is
**not** the legacy `{price, was, on_sale}` string object, and that difference has
a concrete consequence in §8 that must be resolved before implementation.

**FROZEN BOUNDARY — canonical across engine, Laravel and Nuxt** (codex-main,
message 78f14c44, 2026-08-31). Three repos had independently green but mutually
incompatible ProductV1 validators; this is the single definition all three
implement, in `ProductV1::boundStrict()`:

| field | rule |
|---|---|
| *key set* | **EXACT — all 9 present.** Missing or extra key → reject |
| `store` | non-blank, control-free, ≤120 |
| `store_id` | `^[a-z0-9][a-z0-9_-]{0,39}$` |
| `title` | non-blank, control-free, **≤300** |
| `url` | absolute HTTPS ≤2048; no whitespace/control/userinfo/**fragment**; **query allowed** |
| `image` | `null` **or** the same URL rule (CDN query strings are legitimate) |
| `current_price` / `list_price` | `null` **or** exactly `{amount, currency}`; finite non-negative *numeric* amount; **already-uppercase** 3-letter currency |
| `availability` | **required**, one of `in_stock, out_of_stock, preorder, backorder, unknown` |
| `observed_at` | **required**, strict UTC `^YYYY-MM-DDTHH:MM:SS(.mmm)?Z$`, a real instant, ≤5 min in the future |
| *list* | max 24; **any** malformed product rejects the WHOLE delivery |

Three points that are easy to get subtly wrong, and that the tests pin:

- **`null` ≠ absent.** `image`, `current_price` and `list_price` may be null,
  but the KEY is still required. Letting a producer omit a key is precisely how
  the three validators drifted apart in the first place.
- **Currency is not normalized here.** `"usd"` is rejected, not uppercased.
  Accepting it and storing `"USD"` would let the repos disagree about the wire
  format indefinitely without anyone noticing. (`money()`, on the READ path,
  stays lenient — it parses rows written before this freeze.)
- **The timestamp is validated by calendar, not by `strtotime()`.** `strtotime`
  rolls `2026-02-30` forward to March 2 rather than rejecting it, so a malformed
  timestamp would become a plausible-looking fact. Components are checked with
  `checkdate()` plus explicit hour/minute/second ranges.

Returned products are **rebuilt from validated scalars** in the frozen key
order — never the caller's array — so no attacker-owned reference survives the
boundary. This supersedes the earlier nullable `availability`/`observed_at`,
title ≤255, URL ≤2000, loose timestamps and fragment-tolerant image rules.

- **`delivery_id` is the receipt/idempotency key**, replacing `event_id`
  throughout (§3).
- **`assistant_part` must agree exactly with `result.products`.** Two
  projections of the same result are two chances to be wrong, and the one we
  persist is the one the customer sees. Laravel compares them and refuses to
  persist a divergent pair (§7). It never merges them and never picks a winner.
- `outcome` and `error_code` — not `status`/`error` — drive the terminal
  transition and the stored failure reason (§7, §7b, §11).

Ack response — **exact**, and in the engine envelope:

```jsonc
// 202
{ "ok": true, "data": { "schema_version": 1, "delivery_id": "dlv_…",
    "accepted": true } }
```

`403` bad/stale/unverifiable signature, `404` when disabled. **Never** echo the
delivered body back beyond the `delivery_id` above.

#### 1.5 Webhook authentication — **frozen P1 header set**

The earlier two-header scheme is withdrawn. These five headers are the contract:

```
X-Boxly-Key-Id:          <key id>
X-Boxly-Timestamp:       <unix seconds>
X-Boxly-Nonce:           <nonce>
X-Boxly-Content-SHA256:  <lowercase hex SHA-256 of the exact raw request bytes>
X-Boxly-Signature:       v1=<lowercase hex HMAC-SHA256>
```

**Canonical signing bytes** (UTF-8, `\n` = LF, no trailing newline):

```
"v1\n" + timestamp + "\n" + nonce + "\n" + body_sha256
```

Note what is and is not in there: the signature covers the **hash** of the body,
not the body itself, so the content-hash check is not optional decoration — it
is the only thing binding the signed envelope to the bytes we actually received.
Verify the hash first, then the signature; skipping the first makes the second
meaningless.

Verification requirements:

- **Key id** — look up the configured secret for that id; unknown id → 403. This
  is what makes secret rotation possible without a flag day.
- **Timestamp** — ±300s skew, config not a literal (§7).
- **Nonce** — bounded syntax and length, rejected if malformed. It is *inside*
  the signature, so it cannot be rewritten by a replayer.
- **Content hash** — recomputed over the exact raw bytes **before** JSON
  decoding, compared **constant-time**, so an altered body never reaches the
  parser.
- **Signature** — recomputed over the canonical bytes above and compared with
  `hash_equals` (the posture `ProductIndexController:43` already uses: a plain
  `===` leaks the secret one byte at a time). Compare the hex payload after the
  `v1=` prefix; an absent or unknown prefix is a 403, not a fallback.

**No nonce cache in P1, deliberately — and here is the exact division of
labour**, because these two mechanisms are easy to confuse:

- The **authenticated nonce prevents canonical ambiguity.** It makes each signed
  envelope unique, so two different deliveries can never share a canonical
  string, and no signature can be lifted from one context into another.
- **`delivery_id` prevents repeated effects.** It is the durable arbiter (§3),
  persisted in a unique index, and it is what makes a replay a no-op no matter
  how many workers race.

A nonce cache would only add in-window replay *rejection*, and P1 does not need
it: a replayed delivery is already effect-free, and by contract it **must return
the idempotent ack**, not an error. Rejecting a replay would be worse behaviour,
not better — the engine retrying a delivery it is unsure about is correct, and
must be met with the same `202` it would have got the first time.

**Same `delivery_id` with a different content hash is a conflict, not a
replay.** Two different bodies claiming one delivery id means one of them is
wrong, and treating it as a duplicate would silently drop real data. The receipt
therefore stores `content_sha256`, and:

- same id + same hash → idempotent `202` ack, no effects;
- same id + different hash → **409**, `ok:false`, no effects, logged loudly;
- unseen id → `202` ack and dispatch.

**How that is decided is not a read — see §3 and §7.** An earlier draft had the
controller SELECT the receipt before writing it; that was racy, because two
concurrent same-id/different-body requests could both find nothing and both
answer `202`, making the `409` above a claim the code could not keep. The
decision is made by the **write**: `insertOrIgnore` on the unique
`delivery_id`, and whoever loses reads the winner's row inside the same
transaction. The index decides what happens; the response reports what the index
decided.

#### 1.6 Service-plane authentication — **frozen outbound header set**

Laravel → engine. The earlier `Authorization: Bearer {service_secret}` scheme is
**withdrawn**: a bearer token is a static credential replayable against any
endpoint by anyone who observes one request. These five headers replace it, and
are the same shape as §1.5 in the other direction:

```
X-Boxly-Key-Id:          <LIVE_SHOPPING_SERVICE_KEY_ID>
X-Boxly-Timestamp:       <unix seconds>
X-Boxly-Nonce:           <nonce>
X-Boxly-Content-SHA256:  <lowercase hex SHA-256 of the exact sent bytes>
X-Boxly-Signature:       v1=<lowercase hex HMAC-SHA256>
```

**Canonical signing bytes** (UTF-8, `\n` = LF, no trailing newline) — note this
is a *different* string from §1.5, deliberately:

```
"v1\n" + METHOD + "\n" + PATH + "\n" + timestamp + "\n" + nonce + "\n" + body_sha256
```

The outbound string additionally binds **METHOD and PATH**, so a signed body
cannot be lifted and replayed against a different endpoint — e.g. a create body
replayed at `/cancel`. The inbound direction does not need this because the
engine only ever calls the single webhook path.

- **Serialize the JSON body ONCE** and send those exact bytes (`withBody`), the
  same bytes that were hashed. Re-encoding between signing and sending is the
  classic way to ship a signature that never verifies.
- **`rawurlencode` the engine session id** before it goes into a path, and sign
  the encoded path — the path that is signed must be the path that is sent.
- `LIVE_SHOPPING_SERVICE_KEY_ID` joins base_url and service_secret as a
  **required** config value: unset means the feature is OFF, never open.
- Both directions' canonical strings live in one class
  (`app/Support/LiveShoppingSignature.php`) so the difference between them stays
  visible in one file instead of drifting apart in two.

**Field spelling.** snake_case at the HTTP boundary in both directions.
EngineV1 and the §1.5 / §1.6 header sets are now **frozen** — nothing in this
section is awaiting an answer. A later change to the engine contract changes
this file first, and the controller only after.

### 2. Migration — `live_shopping_sessions`

- [x] `2026_09_XX_000000_create_live_shopping_sessions_table.php`
  - `id`
  - `user_id` → `foreignId()->constrained()->cascadeOnDelete()`
  - `conversation_id` → `foreignId()->nullable()->constrained()->nullOnDelete()`
        — the pattern of `2026_07_26_000000_add_conversation_id_to_purchase_requests_table.php`.
        **Required at validation (§1.1), nullable in the schema** — those are not
        in conflict: the FK is `nullOnDelete`, so deleting a conversation nulls
        this column *while a session is running*. §7 must therefore handle a
        delivery whose `conversation_id` correlates against a local `null`:
        that is a deleted thread, so skip the append, log, and still complete the
        terminal transition. It is not a correlation failure.
  - `engine_session_id` string nullable **unique** — nullable because the row
    exists before the engine answers; unique so a replayed delivery cannot fan
    out to two rows
  - `status` string(20) indexed — `pending|running|completed|failed|cancelled`
  - `stores` JSON — one entry in P1, the shape P2 needs, so P2 adds no migration.
    Populated from the single `store_id`; the API response exposes the flat
    `store_id` (§1.1) and keeps this column as the P2-shaped store of record.
  - **`latest_seq` unsignedBigInteger nullable** — the sequence floor echoed by
    the accepted create response (§1.2)
  - **`terminal_seq` unsignedBigInteger nullable** — the sequence of the delivery
    that drove the terminal transition, recorded and bounded (§7)
  - **`expires_at` timestamp nullable, indexed** — the **engine-issued** hard
    deadline echoed by the accepted create response. Not derived from
    `created_at`: a blind local timeout would kill sessions the engine still
    considers alive, and would drift from whatever the engine actually enforces.
    Nullable only for the window between INSERT and the engine's reply.
  - `terminal_delivery_id` string nullable — which delivery drove the terminal
    transition (audit; the receipt table in §3 is the enforcement). **Renamed
    from `terminal_event_id`**: EngineV1 keys deliveries by `delivery_id`.
  - `error_code` string(120) nullable — the failure reason, taken from
    `result.error_code`, or `'expired'` from the reconciler. **Renamed from
    `error`** and narrowed: EngineV1 sends a code, not prose.
  - **`active_slot` unsignedTinyInteger nullable**, with
    **`unique(['user_id','active_slot'])`** — this is the one-active-session
    constraint
  - `timestamps`; indexes `['user_id','status']`, `created_at`,
    **`['status','expires_at']`** (the reconciler's query, §7b)

**Why `active_slot` and not a filtered index.** The natural expression is
"UNIQUE(user_id) WHERE status IN ('pending','running')". **MySQL has no partial
/filtered unique indexes** — that is a Postgres feature. The two MySQL-viable
options:

1. A **stored generated column** (`IF(status IN (…), user_id, NULL)`) with a
   unique index. MySQL 5.7+ supports this and NULLs never collide, so it works —
   but the expression is MySQL-specific (`IF(…)` vs SQLite's `CASE WHEN`), and
   `phpunit.xml` does not pin `DB_CONNECTION`, so the suite may run on SQLite
   while production runs MySQL. A constraint that exists in prod but not under
   test is worse than no constraint.
2. **An application-maintained nullable column** (`active_slot`), set to `1` on
   create and to `NULL` in the same transaction as any terminal transition, with
   a plain composite unique index. NULLs do not collide in a unique index on
   **either** MySQL or SQLite, so the constraint is real in prod *and* exercised
   by the tests.

**Chosen: option 2.** Two concurrent creates for one user → one INSERT wins, the
other raises a duplicate-key `QueryException` → caught → **409**, no race-prone
precheck anywhere. The cost is that the terminal transaction must null the slot,
which it is already doing atomically (§7).

- [x] Deliberately **no lease column**. P1 is view-only; a column with no FSM
      behind it is dead weight. `expires_at` is not a lease — nothing renews it,
      nothing takes it; it is the engine's stated death time, and the only thing
      Laravel does with it is reconcile.

### 3. Migration — `live_shopping_webhook_receipts` (the **durable inbox**)

This table is not an audit log with a unique index bolted on. It **is** the
inbox: the webhook's only job is to get a row committed here, and everything
downstream reads from it.

- [x] `2026_09_XX_005000_create_live_shopping_webhook_receipts_table.php`
  - `id`
  - **`delivery_id` string unique** — the arbiter
  - **`content_sha256` string(64)** — makes "same `delivery_id`, different body"
    a detectable **conflict** rather than a silent duplicate (§1.5). Without it,
    two different bodies sharing one id look identical to a replay and one is
    dropped without trace.
  - **`payload` JSON** — the bounded, already-validated body. Stored because the
    receipt must be self-sufficient: the job is handed a receipt id and nothing
    else, so the request body must survive the response.
  - **`status` string(20) indexed** — `received | processed | conflict | failed`
  - `live_shopping_session_id` nullable foreignId, `outcome` string(20) nullable,
    `terminal_seq` unsignedBigInteger nullable — filled in by the job
  - `error_code` string(120) nullable, `attempts` unsignedTinyInteger default 0
  - `received_at`, `processed_at` nullable, `timestamps`
  - index `['status','received_at']` — the drainer's query (§7c)

**Why the previous SELECT-then-decide design was wrong.** It read the receipt
before writing it, so two concurrent same-id/different-body requests could both
find nothing and both answer `202` — the advertised `409` was not deterministic.
The fix is to make the **write** the decision point: `insertOrIgnore` first, and
let whoever loses the unique index read the winner's row *inside the same
transaction*. The index decides; the response reports what the index decided.

### 4. Models

- [x] `$fillable`, `$casts` (`stores` → `array`, `expires_at` → `datetime`,
      `latest_seq`/`terminal_seq` → `integer`), status constants
- [x] **Status is ours; `result.outcome` is the engine's.** They happen to share
      the three terminal words, and the job maps one to the other explicitly
      rather than assigning across (§7 step 5). A future EngineV1 outcome we do
      not know must fail validation, not become an unknown status string in our
      column.
- [x] `user()`, `conversation()` relations
- [x] `scopeActive()` — `whereIn('status', ['pending','running'])`.
      **`running` is reachable in P1** (was G4): the row is `pending` from INSERT
      until the engine's accepted response comes back, then `running`. The engine
      returns `running` only after durable acceptance, so the state means "the
      engine has committed to this session", not "we sent a request".
- [x] `isTerminal()` — status in `completed|failed|cancelled`
- [x] No business logic; transitions live in the job (§7)

`app/Models/LiveShoppingWebhookReceipt.php`:

- [x] `$fillable`, `$casts` (`payload` → `array`, `received_at`/`processed_at` →
      `datetime`), status constants `received|processed|conflict|failed`
- [x] `session()` relation; `scopeDrainable()` — `status='received'` and
      `received_at` older than the grace (§7c)

### 5. Routes — `routes/api.php`

Root-prefixed. **No `/api` anywhere** (CLAUDE.md rule 10).

Inside the existing `auth:sanctum` group:

- [x] `POST /live-shopping/sessions` → `store` — `throttle:10,1`
- [x] `GET  /live-shopping/sessions/{session}` → `show` — `throttle:60,1`
- [x] `POST /live-shopping/sessions/{session}/ticket` → `ticket` —
      `throttle:30,1`. **POST, not GET** (§1.3).

Every one is throttled explicitly: create is an engine round-trip that claims
the customer's one active slot, and ticket mints a short-lived media credential.
Neither should be spammable.

Outside it (authenticated by HMAC, not Sanctum):

- [x] `POST /live-shopping/webhook` → `LiveShoppingWebhookController::handle` —
      `throttle:120,1`, plus a 256 KiB raw-body cap enforced in the controller
      **before** hashing or decoding.

Instructions, cancel and lease are P2/P3 and deliberately absent.

### 6. Controller, engine client, validation

- [x] `LiveShoppingController` — ownership copied **verbatim** from
      `ConversationController::authorizeOwner`; a second ownership idiom is a
      second thing to get wrong.
- [x] `store()` validates: **`conversation_id` required, integer, and owned by
      the caller** (EngineV1 is conversation-attached — §1.1; and an unowned id
      would attach a session to someone else's thread); `objective` required
      ≤500; `store_id` required and matching `^[a-z0-9][a-z0-9_-]{0,39}$`.
- [x] **No retailer allowlist.** Laravel validates the *shape* of `store_id` and
      lets the engine own which stores exist; an allowlist here would need
      editing every time the engine learns a store.
- [x] **No origin validation, because there is no origin.** EngineV1 removed
      `store.origin` (§1.1) — the engine owns the catalog and the origin. The
      old plan's SSRF-hygiene block (https / no userinfo / no IP literal / no
      private range) is **deleted, not relaxed**: Laravel no longer accepts,
      stores or forwards any caller-influenced URL, so there is nothing left to
      sanitize. Do not reintroduce the check for a field that no longer exists.
- [x] `ticket()` — **calls the engine on every request**
      (`POST {base_url}/v1/sessions/{engine_session_id}/viewer-tickets`, body per
      §1.3), unwraps `ok`/`schema_version`, passes only the five public fields
      through, persists nothing, refuses a terminal session with 409, and returns
      the honest 503 of §11 when the engine is unreachable or its body is
      incomplete or its `expires_at` is >60s out.
- [x] `LiveShoppingEngine` service — `config('services.live_shopping_engine')`
      (`enabled`, `base_url`, `service_secret`, `webhook_secret`, `timeout`,
      `expiry_grace` — **no callback base**, the engine owns the destination),
      env-only values, short explicit timeout (the chat is interactive; a hung
      engine must not hold a web worker), `Idempotency-Key` from the local row
      id, typed `LiveShoppingEngineException`. The client never renders HTTP.
- [x] The client owns the **EngineV1 envelope**: sends `schema_version: 1`,
      requires `ok: true` and a known `schema_version` on every response, and
      unwraps `data` before anything else sees it. A controller must never touch
      `ok`/`schema_version`.
- [x] **Create validates correlation** (§1.2): `data.session.conversation_id`
      equals the local conversation id and `data.session.store_id` equals the
      requested store id, both compared as strings. Mismatch → failed create,
      because the alternative is attaching another session's live stream to this
      customer's thread. Persist `session.id`, `expires_at` and `latest_seq`.

### 7. Webhook: verify, then idempotent atomic processing

**Verification order matters** — each step is cheap and rejects before the next.

- [x] **Verify the frozen header set of §1.5, in this order** — each step is
      cheaper than the next and rejects before it:
  1. **`X-Boxly-Key-Id`** → configured secret lookup; unknown → 403.
  2. **`X-Boxly-Timestamp`** → present, integer Unix seconds, within **±300s**
     (config, not a literal; it must exceed plausible engine↔DO clock drift).
  3. **`X-Boxly-Nonce`** → present, bounded syntax and length.
  4. **`X-Boxly-Content-SHA256`** → recompute lowercase-hex SHA-256 over the
     **exact raw request bytes**, compare **constant-time**. Done *before* JSON
     decoding, so an altered body never reaches the parser.
  5. **`X-Boxly-Signature`** → strip the `v1=` prefix (absent/unknown prefix →
     403, never a fallback), recompute HMAC-SHA256 over the canonical UTF-8 bytes
     `"v1\n" + timestamp + "\n" + nonce + "\n" + body_sha256`, compare with
     **`hash_equals`** — the constant-time posture `ProductIndexController:43`
     already uses, whose comment says why: a plain `===` leaks the secret one
     byte at a time.
- [x] **The signature covers the body's hash, not the body.** So step 4 is not
      belt-and-braces — it is the only thing binding the signed envelope to the
      bytes received. Doing 5 without 4 authenticates nothing about the payload.
- [x] **No nonce cache in P1** (§1.5). The authenticated nonce prevents canonical
      ambiguity; `delivery_id` prevents repeated effects. A replay is already
      effect-free and **must receive the idempotent ack** — rejecting it would be
      worse behaviour, since an engine retrying a delivery it is unsure about is
      doing the right thing.
- [x] **Replay vs conflict is decided by the WRITE, not a read.** After
      signature verification and bounded shape validation, open a transaction:
  1. `insertOrIgnore` the receipt keyed by `delivery_id`, with
     `content_sha256`, the bounded validated `payload`, `status='received'`,
     `received_at`.
  2. **Insert lost** → read the existing row **in the same transaction**:
     same `content_sha256` → the §1.4 `202` idempotent ack, **no new work**;
     different hash → **`409`** with `ok:false`, no work, logged loudly.
  3. **Insert won** → hand the receipt to processing (§7a) and answer `202`.
- [x] **A valid delivery is never acked until its inbox row is committed.** The
      `202` is a durability promise: it tells the engine "this will happen".
      Acking before the commit would make that promise a guess.

#### 7a. From durable receipt to processing — no crash window

- [x] The job takes a **receipt id only**. It does not insert the receipt, does
      not re-verify signatures, and does not read the HTTP request — everything
      it needs is the committed row. That is what makes it safely re-runnable.
- [ ] **⚠️ The atomic-dispatch variant could not be proven in this environment,
      so the plan takes the fallback codex named.** Dispatching inside the
      transaction is only crash-safe if the `jobs` INSERT joins that same
      transaction. The config is *consistent* with it —
      `config/queue.php` has `'connection' => env('DB_QUEUE_CONNECTION')`
      (unset → default DB connection) and `'after_commit' => false` — but this
      checkout has **no `vendor/`, no PHP binary and no container**, so the
      framework behaviour cannot be demonstrated here, only asserted from
      memory. An unproven atomicity claim guarding a crash window is exactly the
      thing not to assert.
- [x] **Design taken — the receipt is the source of truth, dispatch is only an
      optimisation.** It is correct whether or not the queue shares the
      transaction:
  1. Commit the receipt (`status='received'`). Only then answer `202`.
  2. **After** the transaction commits, dispatch
     `ProcessLiveShoppingResultJob($receiptId)` on the explicit `database`
     connection. Normal path: processed in well under a second.
  3. **§7c's drainer is the guarantee.** If the dispatch never happened — the
     process died between commit and dispatch, the queue was down, the row was
     written by some future path — the drainer picks the row up. Nothing is lost
     and nothing depends on framework-internal transaction coupling.
- [x] Dispatching after commit is **not** the classic "job runs before its data
      is committed" bug: the receipt is committed first, and the job reads only
      the receipt.
- [ ] **Optional proof gate.** `QueueTransactionalityTest` — inside a
      transaction, dispatch on the `database` connection, roll back, assert the
      `jobs` table is empty. If that passes in a real environment, dispatching
      *inside* the transaction becomes available as a latency micro-optimisation.
      It is not required: the drainer already closes the window, so this test is
      evidence, not a dependency.

`ProcessLiveShoppingResultJob($receiptId)` — **one transaction, `database` queue**:

`ProcessLiveShoppingResultJob` — **one transaction, database queue**:

- [x] `DB::transaction(function () { … })` containing, in order:
  1. Load the receipt by id with `lockForUpdate()`. **Status is not `received`
     → another worker already processed it → return.** The receipt already
     exists (§7a), so the job never inserts it; the row lock plus the status
     transition `received → processed` is what makes a duplicate dispatch — from
     a queue retry, or from the drainer racing the fast path — a no-op.
  2. `LiveShoppingSession::where('engine_session_id', $sessionId)
     ->lockForUpdate()->first()` — unknown session → log and return, never a 500.
  3. **Correlation check.** `conversation_id` must equal the session's
     conversation. A **local null** is the deleted-thread case (§2): skip the
     append, log, still complete the terminal transition. A **mismatch** is a
     misrouted delivery: record the receipt, change nothing else, log loudly.
  4. **`terminal_seq` recorded and bounded.** Must be a non-negative integer
     within unsigned-bigint range and **≥ the `latest_seq`** persisted at create.
     Out of bounds or lower → treat as misrouted/stale: receipt kept, no append.
     Persist it on the row.
  5. **Terminal transitions:** `pending|running → completed|failed|cancelled`,
     driven by **`result.outcome`**. Terminal states are **absorbing**: if the
     session is already terminal, the receipt is kept (so the delivery is not
     reprocessed later) and nothing else changes. **First terminal delivery
     wins** — a `failed` arriving after a `completed` does not overwrite it, and
     vice versa; the conflict is recorded in the receipt table rather than
     silently resolved.
  6. Append the conversation message (§8) — **only** if the correlation in step
     3 passed with a non-null conversation AND the conversation's `user_id`
     still equals the session's `user_id`.
  7. Write the `SearchEvent` with `source='live_engine'` (§9).
  8. Set the session's `status`, `error_code` (from `result.error_code`, ≤120,
     when `outcome=failed`), `terminal_delivery_id`, `terminal_seq`, and
     **`active_slot = null`** (releasing the one-active-session slot).
  9. Mark the **receipt** `processed` with `processed_at`. Every early return
     above also lands the receipt in a terminal state (`processed` for a
     no-effect outcome, `conflict`/`failed` where that is what happened) — a
     receipt must never be left `received` after a completed run, or the drainer
     will pick it up forever.
- [x] Message, SearchEvent and terminal state commit **together or not at all**.
      A half-applied terminal — the message appended but the status still
      `running` — is the failure mode this transaction exists to prevent.

### 7b. Scheduled reconciliation against the engine's deadline

Resolves G1. Without this, `active_slot` is claimed at INSERT and released only
by a terminal webhook — so a process that dies mid-create, or an engine that
never delivers terminal, locks that user out of the feature permanently, and P1
has no cancel route to undo it.

- [x] `Schedule::command('boxly:live-shopping-reconcile')->everyMinute()
      ->withoutOverlapping()` in `routes/console.php`. The scheduler already runs
      in production (`supervisord.conf` `[program:scheduler]`), so this costs no
      new infrastructure.
- [x] The command transitions `pending|running` rows whose **`expires_at` is
      past** (plus a small `expiry_grace`, config, so Laravel never beats the
      engine to its own deadline) → `status='failed'`, `error_code='expired'`,
      **`active_slot=null`**, each row in **one transaction** with
      `lockForUpdate()`, so the slot release and the status change cannot
      half-apply.
- [x] **`expires_at IS NULL` is handled, not skipped.** That is exactly the
      crash-before-engine-response row: INSERT committed, the create request
      never returned, so no deadline was ever recorded. Those are failed once
      they are older than the engine call's own timeout plus grace — a bound on
      an unanswered HTTP call, **not** a blind age-based timeout on a live
      session. A row with a deadline is judged only by that deadline.
- [x] **No blind `created_at` timeout anywhere else.** The engine owns the
      lifetime; Laravel only enforces the number the engine gave it.
- [x] **The reaper and a terminal webhook can race.** Both take the same row
      lock, both write an absorbing terminal state, and first-writer-wins (§7).
      So: reaper first → the late webhook finds `failed`, records its receipt,
      appends nothing, changes nothing. Webhook first → the reaper's query no
      longer matches (the row is terminal and its slot already null). Either
      order converges, and neither can double-release or double-append.

### 7c. Inbox drainer — the guarantee behind the 202

- [x] `Schedule::command('boxly:live-shopping-drain')->everyMinute()
      ->withoutOverlapping()`, alongside the reaper (§7b). The scheduler already
      runs in production (`supervisord.conf` `[program:scheduler]`).
- [x] It selects receipts with `status='received'` and
      `received_at` older than a short grace (config, ~30s — long enough that it
      never races the fast path for a job already in flight) and dispatches
      `ProcessLiveShoppingResultJob` for each.
- [x] **A double dispatch is harmless by construction.** The job locks the
      receipt and transitions `received → processed`, so whichever of the fast
      path and the drainer arrives second finds a non-`received` row and
      returns. This is the same arbitration used everywhere else in this plan —
      the database decides, not a timing assumption.
- [x] Bounded batch per tick (e.g. 100) so a backlog drains steadily instead of
      one tick trying to do everything.
- [x] No-op when the feature is disabled (§14).
- [x] **This is what makes the `202` truthful.** Without it, "accepted" would
      mean "we wrote a row and hopefully dispatched a job". With it, the only
      thing that must succeed for the promise to hold is the commit the response
      already waited on.

### 8. Conversation append — the engine's `assistant_part`, bounded

- [x] Appends ONE assistant row — model `ConversationMessage`, `role:'assistant'`,
      `content = ['parts' => [ <the part> ]]`. The `parts` wrapper is not
      cosmetic: `deriveProducts` reads `$m->content['parts']`
      (`ConversationController.php:164`), so a bare parts array is invisible to
      the rail.
- [x] **The part is the engine's `assistant_part`** (§1.4) — `{type:
      'tool-live_results', state:'output-available', output:{products: ProductV1[]}}`
      — after §7's agreement check, then bounded as below. Laravel no longer
      composes this part; it validates and bounds one the engine sent.
- [x] **Naming split (cross-repo, agreed):** `tool-live_results` is the TERMINAL
      result part and the ONLY part Laravel ever writes. `tool-live_verify` is
      the SESSION HANDLE part — `{sessionId, status}` and nothing else — written
      by the frontend when a session opens. The handle part MUST NOT carry
      `output.products`: `deriveProducts` keys off `$part['output']['products']`
      **tool-name-agnostically** (verified at
      `ConversationController.php:164-172`), so anything carrying that key lands
      in the rail whatever the tool is called.
- [x] Set `conversation->last_message_at = now()` inside the same transaction —
      `addMessages` does it and the history sidebar reads that column
      (`ConversationController::index` selects it). Without the bump a live
      result never reorders the thread.

#### 8.1 The ProductV1 money adapter (approved, with a correction)

The problem, verified in code. `deriveProducts` builds each rail entry as:

```php
'price'   => $p['price'] ?? $p['price_usd'] ?? null,   // ConversationController.php:195
'was'     => $p['was'] ?? null,                        // :196
'on_sale' => $p['on_sale'] ?? false,                   // :197
```

ProductV1 carries none of those keys, so a ProductV1 part renders priceless.

**The naive fix was wrong and is rejected.** `?? $p['current_price']` would have
worked only if `current_price` were a scalar. It is a **money object**, so that
fallback puts an array into `price` and `ProductGallery` renders
`[object Object]` — a worse failure than the null it was meant to fix, because
it looks like data.

**Approved adapter — additive, ProductV1-only, presentation-only:**

- [x] **Legacy always wins.** `price` / `price_usd` / `was` / `on_sale`, when
      present, are used exactly as today. The adapter is reached only when the
      legacy key is absent, so no part written by any existing tool can change
      behaviour. That is what makes this safe to add to a shared read path.
- [x] **Extract, don't pass through.** For ProductV1 only, take
      `current_price.amount` → `price` and `list_price.amount` → `was`, as
      **scalar display values**.
- [x] **USD only, per field.** A value is used only when its currency normalizes
      **exactly** to USD (trimmed, case-insensitive). Anything else — MXN, an
      empty currency, a missing one — yields `null` for that field. We are not
      converting currencies in a display adapter, and showing an MXN number in a
      USD-shaped rail would be a lie the customer acts on.
- [x] **Valid means finite and non-negative.** Reject `NaN`, infinities,
      negatives, non-numeric strings, nested structures. Malformed → `null`.
- [x] **Derived `on_sale` is narrow:** true **only** when both `current_price`
      and `list_price` produced valid USD values **and** `list > current`.
      Equal prices → `false`. Either side missing or non-USD → `false`. A
      currency mismatch between the two (e.g. current USD, list MXN) → the list
      value is dropped, so `on_sale` is `false` — comparing across currencies is
      meaningless, and a fabricated "on sale" badge is a claim about money.
- [x] **This is presentation metadata and nothing more.** It is not a checkout
      price, not a landed cost, and not an input to any quote or charge. Nothing
      downstream may read it as one.
- [x] **`assistant_part` is persisted unchanged.** The adapter lives in the read
      path only, so the stored part stays byte-identical to what the engine sent
      and §7's agreement check keeps its meaning.

Tests (§12 `ProductV1MoneyAdapterTest`) — one per way this can go wrong:

- **positive**: valid USD `current_price` + higher USD `list_price` → `price`,
  `was`, `on_sale: true`
- **missing**: no `list_price` → `price` set, `was` null, `on_sale` false
- **malformed**: `amount` non-numeric / negative / infinite / an object → null,
  never `[object Object]`
- **non-USD**: MXN on both → both null, `on_sale` false
- **currency mismatch**: current USD, list MXN → `was` null, `on_sale` false
- **equal prices**: list == current → `on_sale` **false**
- **legacy precedence**: a part carrying both legacy `price`/`on_sale` and
  ProductV1 money objects uses the legacy values unchanged

#### 8.2 Bounding

The engine is trusted, but a trusted upstream that goes wrong must not write an
unbounded blob into a conversation. Applied **after** §7's agreement check, to
the agreed list:

- at most **24** products; extras dropped, not an error
- `url` required, `https` only, ≤2000 chars — anything else drops the item
- `title` ≤255, `store` ≤120, `store_id` ≤40, `availability` ≤40,
  `observed_at` ≤40 — cast to string and trimmed
- `current_price` / `list_price` are **money objects, not strings** (§8.1): keep
  `{amount, currency}` with `amount` bounded as a finite scalar and `currency`
  ≤8 chars. Do not stringify them — the read adapter expects the object
- `image` ≤2000 and `https` only; **`data:` URIs dropped** (matching what
  `deriveProducts` already does at `:187-190`, so the two layers agree)
- unknown keys dropped — the persisted part carries exactly the **ProductV1**
  key set, not the legacy one
- **This is a deliberate, one-way divergence and the only one allowed.** If
  bounding drops items, the persisted part is a strict subset of
  `result.products`. That subset is produced by us, deterministically, after the
  two engine projections were proven identical — it is not the untrusted
  divergence §7 rejects. Worth stating so a later reader does not "fix" §7 to
  compare the persisted part against `result.products` and find a false alarm.

### 9. Source-segmented SearchEvent

- [x] `2026_09_XX_010000_add_source_to_search_events_table.php` — nullable
      indexed `string('source', 32)` after `type`.
- [x] `null` = every existing legacy row, semantics
      **unchanged**; `'live_engine'` = a terminal live-session result set.
- [x] `SearchEvent::$fillable` gains `source`. `SearchEventController::store` is
      **not touched** and keeps writing `null`.
- [x] **The admin aggregates DO need a change — verified, and the earlier claim
      that they were untouched was wrong.** `SearchEventController::stats`,
      `queries` and `export` segment by **`type`**, never by source
      (`stats:175-178` builds `$base` then filters `where('type', TYPE_SEARCH)`;
      `queries:132-133` does `whereIn('type', [search, question])`). A live row
      written as `type='search'` therefore lands in `$totalSearches`, in
      `avgResults`, in the zero-result/broadened quality ratios and in the intent
      map — the objective text mixed straight into the query corpus. Adding a
      `source` column alone segments nothing.
- [x] Fix: the three aggregate paths add `->whereNull('source')`, guarded with
      `Schema::hasColumn('search_events','source')` — exactly the `$hasBroadened`
      idiom already in that same method (`stats:~198`), so the page keeps working
      through the deploy window. Then, and only then, `null` = every existing row
      with unchanged semantics, and
      `'live_engine'` = a terminal live-session result set.
- [x] Until this lands, live sessions write **no** SearchEvent at all rather
      than overloading `type:search` with different semantics.

### 10. Rollout flag

- [x] `config('services.live_shopping_engine.enabled')`, default **false**, and
      forced false unless `base_url` and `service_secret` are both set — the
      original decision, unchanged.
- [x] **No `LIVE_SHOPPING_CALLBACK_BASE`.** Superseded 2026-08-31: the engine
      owns the callback destination (§1). Laravel configures only the outbound
      pair above plus the inbound `webhook_secret`; it holds no callback base, so
      there is no `config('app.url')` trap and no origin for this repo to
      validate. Do not reintroduce one "for completeness" — an unused URL that
      drifts out of date is worse than no URL.
- [x] The webhook **route** is fixed (`/live-shopping/webhook`) and never
      composed from input. It is closed unless `webhook_secret` is set — unset
      secret = off, not open, the same posture `ProductIndexController` takes.
- [x] **Consequence worth naming: the reaper is now the only detector of a bad
      callback mapping.** Laravel cannot see the engine's `boxly-p1` destination
      and so cannot warn that it points at a stale domain or a staging host. A
      wrong mapping looks exactly like an engine that never finishes: sessions
      accept, no terminal webhook arrives, and §7b expires them at the engine's
      own deadline. That is a correct and honest outcome, but it means §7b is
      load-bearing for a misconfiguration it cannot diagnose — worth one line in
      the deploy notes, and worth checking the first live session end-to-end
      rather than trusting the mapping.
- [x] Disabled → the three authed routes return the honest 503 envelope and the
      webhook returns 404. Nothing half-registers; it ships dark.

### 11. Honest error envelopes

Never report a session as running when the engine did not confirm it.

- [x] Unconfigured/disabled → `503 'live shopping is not configured'`
- [x] Engine unreachable/timeout → `503 'the shopping engine is unavailable'`,
      row marked `failed`, `active_slot` released, no invented
      `engine_session_id`
- [x] Engine 4xx, or `ok:false`, or an unknown `schema_version` → `422`
      carrying the engine's own reason
- [x] **Create whose correlation fails** — `data.session.conversation_id` or
      `store_id` not matching what we asked for → failed create (503, row
      `failed`, slot released). Attaching another session's live stream to this
      customer's thread is worse than no session at all.
- [x] Not the caller's session → `403 'Not your session.'`
- [x] Second concurrent session for one user → `409` (from the unique index)
- [x] Ticket for a terminal session → `409`, not a ticket that cannot connect
- [x] Ticket when the engine is unreachable, non-2xx, or returns a body missing
      any of `ticket|expires_at|sse_url|whep_url|ice_servers` → **503**. Never a
      locally-invented ticket, SFU URL or TURN credential: a fabricated one
      renders as a viewer that connects to nothing, which is indistinguishable
      to the customer from a working session.
- [x] Create where the engine answers 2xx but without a usable `expires_at` →
      treated as a failed create (503, row `failed`, slot released). A session
      with no deadline can hold the user's one active slot forever.
- [x] Reconciled-past-deadline session → `failed` with `error_code='expired'`
      (§7b), slot released
- [x] **Webhook-side failures never surface as engine-visible errors beyond the
      documented codes.** A divergent `assistant_part`/`result.products` pair, a
      correlation mismatch or an out-of-bounds `terminal_seq` are all logged and
      receipted, and the ack stays the §1.4 `202` — the engine has delivered
      correctly; the problem is in the payload, and retrying it would not help.
- [x] `Schema::hasTable` guard on read paths (CLAUDE.md deploy-window rule)

### 12. Feature tests — `tests/Feature/LiveShopping/`

No feature tests exist today beyond `ExampleTest`, so P1 sets the pattern. Every
test fakes the engine; none touches a browser. All fixtures use **EngineV1**
bodies — a test written against a legacy shape would pass while production
fails.

> **✅ EXECUTED AND GREEN ON BOTH DRIVERS — 2026-08-31.**
>
> - **SQLite**: `OK (169 tests, 512 assertions)`, 8 skipped (the MySQL-only gate,
>   which skips loudly rather than passing by not running).
> - **MySQL 8.0.32**: `OK (167 tests, 538 assertions)` for
>   `--filter 'LiveShopping|ProductV1'`. See
>   `tasks/live-shopping-mysql-gate.md` for full provenance.
>
> The §7a note that this checkout has "no PHP binary and no container" is
> **stale**: PHP is at `/home/alex/.cache/boxly-php-ci/php-linux-x86_64`, and
> Docker works.
>
> ```bash
> /home/alex/.cache/boxly-php-ci/php-linux-x86_64 vendor/bin/phpunit \
>   --filter 'LiveShopping|ProductV1'
> ```
>
> **Three real defects were found by running these, none by reviewing them** —
> which is the whole argument for the runs:
> `ShowSessionTest::session()` collided with the framework's own `session()`
> (fatal, not a failure); `ProductV1::utcRfc3339` leaned on `strtotime()` to
> reject impossible dates, which it does not do — it rolls `2026-02-30` forward
> to March 2; and, only under MySQL, the engine's RFC3339 `expires_at` was
> written straight into a `timestamp` column, which would have made **every
> successful create a 500 in production** while this suite stayed green.

**Naming note.** The per-concern test names below were consolidated during
implementation into fewer files, because several named cases share fixtures and
splitting them would have meant duplicating the signing helper five ways. The
mapping: `WebhookReplayTest` + `WebhookConcurrentConflictTest` +
`WebhookInboxDurabilityTest` + `WebhookBodyValidationTest` +
`WebhookDivergenceTest` → **`WebhookInboxTest`**; `WebhookCorrelationTest` +
`TerminalSeqTest` + `DuplicateJobTest` + `ReceiptWithoutEffectTest` +
`ResultJobIdempotencyTest` + `ResultJobOwnershipTest` → **`ResultJobTest`**;
`ProductProjectionTest` → **`WebhookBoundaryTest`**; `MetricsUnchangedTest` +
`SchemaGuardTest` + `DisabledFlagTest` → **`ProductionSafetyTest`**. Every
described case survives the merge; none was dropped.

- [x] `CreateSessionTest` — 401 unauth; 201 persisting `engine_session_id`,
      `expires_at` and `latest_seq`, landing on `running` not `pending`;
      **`conversation_id` now REQUIRED** (missing → 422) and another user's
      **rejected**; missing/invalid `objective` and `store_id`; **a store id
      unknown to this repo is ACCEPTED**, proving no retailer allowlist was
      introduced; engine 503 leaves no row claiming to run; an engine 2xx with no
      usable `expires_at` → failed create (503, row `failed`, slot released);
      **`ok:false` and an unknown `schema_version` → 422**; **a second create
      while one is active → 409**; and a concurrency test that inserts the
      conflicting row directly to prove the **database** refuses it rather than a
      precheck.
- [x] `CreateCorrelationTest` — an engine 2xx whose `data.session.conversation_id`
      or `store_id` differs from what was requested is a **failed** create: 503,
      row `failed`, slot released, no `engine_session_id` persisted. This is the
      test that would catch a stream being attached to the wrong thread, and
      nothing about expiry or status would catch it.
- [x] ~~`StoreOriginValidationTest`~~ — **deleted.** EngineV1 removed
      `store.origin`, so there is no origin to validate; its one surviving
      assertion (unknown store id accepted → no allowlist) moved into
      `CreateSessionTest` above.
- [x] `ShowSessionTest` — owner 200; other user 403; missing 404.
- [x] `TicketTest` — the request body carries `schema_version`, the **string**
      `user_id` and exactly `["events:read","media:read"]`; the wrapped reply is
      unwrapped and only the five public fields reach the client (no `ok`, no
      `schema_version`); `expires_at` ≤60s; **an `expires_at` >60s out is
      rejected as invalid, not clamped**; other user 403; terminal session 409;
      the ticket value is not persisted on the row; engine unreachable → 503;
      engine 200 with `ice_servers` missing → 503, never a partial ticket.
- [x] `ReconcileTest` (§7b) — four cases, one per failure the deadline exists to
      cover:
  - **crash before the engine responded**: a row with `active_slot=1`,
    `status='pending'`, `expires_at=null`, older than the call timeout + grace →
    `failed`, slot released, and the user can create again.
  - **accepted, no terminal ever arrives**: `running` with a past `expires_at` →
    `failed` / `error_code='expired'` / slot released; and a `running` row whose
    `expires_at` is still in the future is **left alone** (no blind age timeout).
  - **late terminal webhook**: after the reaper has marked it `failed`, a valid
    terminal delivery records its receipt, appends **no** message, writes **no**
    SearchEvent and does **not** flip `failed` back to `completed`.
  - **reaper/webhook race**: run both orders against the same row and assert the
    same converged end state — one terminal status, one slot release, at most one
    append.
- [x] `WebhookSignatureTest` — the frozen header set of §1.5, one case per
      element that can be forged or omitted: unknown **`X-Boxly-Key-Id`** 403;
      missing timestamp 403; **stale (301s old)** 403; **future (301s ahead)**
      403; missing or malformed **nonce** 403; **`X-Boxly-Content-SHA256` not
      matching the raw bytes** 403 (and asserted to reject *before* JSON
      decoding, so a body that is both altered and unparseable still 403s rather
      than 500s); signature missing the `v1=` prefix 403; **any single signed
      element rewritten while the other headers stay valid** 403 (timestamp,
      nonce and body hash each get their own case — that is the property the
      canonical string exists to guarantee); a valid delivery → the §1.4 ack body
      **exactly** and the job dispatched; disabled flag 404.
- [x] `WebhookReplayTest` — the same delivery sent twice, byte-identical:
      **both** get the §1.4 `202` ack (a replay is never an error), exactly one
      receipt row exists, and effects happen exactly once. Then the conflict:
      same `delivery_id`, **different** body, correctly re-signed → **409** with
      `ok:false`, no second receipt, nothing appended.
- [x] `WebhookConcurrentConflictTest` — the case that killed the previous
      design. Pre-insert the receipt row for a `delivery_id` with body A's hash
      (standing in for the request that won the race), then deliver body B with
      the same id. **Deterministically 409**, because the decision is made by the
      unique index on write, not by a read that both requests could lose.
- [x] `WebhookInboxDurabilityTest` — a valid delivery commits a receipt with
      `status='received'`, the bounded `payload` and `content_sha256`, **before**
      the `202` is returned; and a delivery that fails bounded validation leaves
      **no** receipt at all (rollback before commit — nothing half-accepted).
- [x] `InboxDrainerTest` (§7c) — a `received` receipt older than the grace is
      picked up and processed; one inside the grace is left alone; a
      **`processed`** receipt is never re-dispatched; and the **crash/retry**
      case: a receipt committed with no job ever dispatched is still processed,
      exactly once, with no message duplicated. This is the test that proves
      there is no crash window between the durable receipt and the work.
- [x] `DuplicateJobTest` — dispatching `ProcessLiveShoppingResultJob` twice for
      the same receipt id (fast path + drainer racing) appends exactly one
      message and writes exactly one `SearchEvent`, asserted by count. The
      receipt's `received → processed` transition under `lockForUpdate()` is what
      makes the second run a no-op.
- [x] `ReceiptWithoutEffectTest` — every early-return path (unknown session,
      correlation mismatch, out-of-bounds `terminal_seq`, divergent
      `assistant_part`, already-terminal session) leaves the receipt in a
      **terminal** state, never `received`. A receipt stuck at `received` would
      be re-dispatched by the drainer forever.
- [ ] `QueueTransactionalityTest` (optional, §7a) — inside a transaction,
      dispatch on the `database` connection, roll back, assert `jobs` is empty.
      Evidence for whether in-transaction dispatch is available as an
      optimisation; **not** a dependency, since §7c already closes the window.
- [x] `WebhookBodyValidationTest` — unknown `schema_version` rejected; missing
      `delivery_id` / `session_id` / `terminal_seq` / `conversation_id` rejected;
      `result.outcome` outside `completed|failed|cancelled` rejected;
      `assistant_part.type` other than `tool-live_results` rejected.
- [x] `WebhookDivergenceTest` — an `assistant_part.output.products` that differs
      from `result.products` (different length, order, or any ProductV1 value):
      **neither is persisted**, no message appended, the receipt is recorded, the
      terminal transition still completes, and the ack is still 202.
- [x] `WebhookCorrelationTest` — a delivery whose `conversation_id` does not
      match the session's: receipt recorded, nothing appended, logged. Separately
      the **deleted-thread** case: local `conversation_id` is null (FK
      `nullOnDelete` fired mid-session) → no append, no error, terminal
      transition still completes.
- [x] `TerminalSeqTest` — a `terminal_seq` below the persisted `latest_seq`, a
      negative one, and one outside unsigned-bigint range are each treated as
      stale/misrouted: receipt kept, no append. A valid one is persisted on the
      row.
- [x] `ResultJobTest` — appends the engine's `assistant_part` and
      `deriveProducts` surfaces it through `GET /conversations/{id}`; exactly one
      `SearchEvent` with `source='live_engine'`; `active_slot` released; unknown
      `engine_session_id` dropped without a 500.
- [x] **`ProductV1MoneyAdapterTest` — the §8.1 suite.** Seven cases: positive,
      missing `list_price`, malformed `amount`, non-USD, currency mismatch, equal
      prices, and legacy precedence (see §8.1 for the expected value of each).
      Asserted through `GET /conversations/{id}`, i.e. against the rail the
      customer actually sees. Two of these are the ones that matter most: without
      the adapter the positive case yields a **priceless** rail, and with the
      naive `??` fallback it yields **`[object Object]`** — both throw no error
      and both look shipped.
- [x] `ResultJobIdempotencyTest` — **two jobs with the SAME `delivery_id` append
      exactly one message and exactly one SearchEvent**, asserted by count.
      `phpunit.xml` pins `QUEUE_CONNECTION=sync`, so "dispatched while the first
      is in flight" is not expressible; the property is proven instead by (a)
      running the same job twice and counting, and (b) pre-inserting a receipt
      row with that `delivery_id` and asserting the job then appends nothing —
      exactly the state a racing worker leaves behind. Note SQLite makes
      `lockForUpdate()` a no-op, so the unique `delivery_id` index is what these
      tests actually exercise; that is the intended arbiter anyway. Plus: a
      **second, different** `delivery_id` arriving after a terminal state does
      not append, does not change status, and does not overwrite `completed` with
      `failed`.
- [x] `ResultJobOwnershipTest` — with the conversation's `user_id` mutated to a
      different user (simulating corruption or a re-parented row), the job
      **does not append across owners**; it skips the message, logs, and still
      completes the terminal transition.
- [x] **Product bounding — REJECT, do not truncate.** Superseded during
      implementation and folded into `WebhookBoundaryTest`. The draft above said
      "100 items truncated to 24; a bad image dropped; an item with no `url`
      dropped", i.e. silently repair the delivery. That is wrong: quietly
      shrinking the list hides a broken engine from us AND changes what the
      customer is shown, and it would defeat the
      `assistant_part` ⇄ `result.products` agreement check in §1.4, since the
      two sides would be repaired independently. `ProductV1::boundStrict()`
      therefore returns `null` — rejecting the WHOLE delivery — for an over-cap
      list, an unknown key, a missing required field, a non-https/credentialed
      `url` or `image`, or a numeric-string price. Cases live in
      `WebhookBoundaryTest`.
- [x] `DisabledFlagTest` — see §14; it is the rollback evidence, not just a flag
      test.
- [ ] **Optional MySQL gate.** `docker-compose.yml` ships `mysql/mysql-server:8.0`
      with sail's `create-testing-database.sh`, so the suite *can* be run against
      real MySQL (`DB_CONNECTION=mysql` inside the container) to exercise
      `lockForUpdate()` and MySQL's own unique-index behaviour. Worth doing once
      for the concurrency tests; **not required** for a local run, and CI stays on
      the default connection.

### 13. Deployment verification (CLAUDE.md "probe, don't assume")

- [ ] Probe for **401** — proof the route exists and is auth-gated — not 200:
      `curl -s -o /dev/null -w "%{http_code}\n" -X POST https://api.boxly.mx/live-shopping/sessions -H "Accept: application/json"`
- [ ] 404/405 → not deployed yet, or a stale route cache
      (`php artisan route:clear`).

---

### 14. Production safety — `main` auto-deploys

**`main` is production.** A commit to `main` in this repo rebuilds
`api.boxly.mx` on its own (CLAUDE.md, Deploying). Nothing here is committed or
pushed; when implementation is approved it stays local until Alex says
otherwise. Everything below exists so that *when* it does land, landing it is a
non-event.

#### 14.1 Fail closed

- [x] Default **disabled**. `enabled` is false unless `base_url` and
      `service_secret` are both set (§10); the webhook is closed unless
      `webhook_secret` is set. A half-configured deploy behaves exactly like an
      unconfigured one — never partially live.
- [x] Disabled → the three authed routes return the honest `503` envelope and the
      webhook returns `404`. The routes are **registered but inert**: registered
      so a deploy probe (§13) gets a truthful answer, inert so no code path
      touches the engine, the queue or a conversation.
- [x] The reaper (§7b) **and the inbox drainer (§7c)** are no-ops when disabled —
      neither may sweep rows for a feature that is off.

#### 14.2 Migrations are additive and backward-compatible

- [x] **Two new tables and one nullable column.** `live_shopping_sessions` and
      `live_shopping_webhook_receipts` (the durable inbox, §3) are new, so
      nothing existing can break on them. The only touch to an existing table is
      `search_events.source` — **nullable, no default, no backfill** (§9).
      Existing rows stay `null` and keep their exact current meaning.
- [x] **No existing column is altered, renamed, dropped or reordered.** The
      renames in this plan (`event_id`→`delivery_id`,
      `terminal_event_id`→`terminal_delivery_id`, `error`→`error_code`) are all
      inside tables that do not exist yet — they are edits to this plan, not
      migrations against live data.
- [x] **The old code runs fine against the new schema.** A nullable added column
      is invisible to every existing query, which is what makes the deploy
      window safe in both directions: code-then-migration or migration-then-code.
- [x] `down()` on all three drops exactly what `up()` added
      (`dropIfExists` / `dropColumn`), so a rollback is clean.

#### 14.3 The existing AI-search and admin metrics do not move

This is the one place P1 touches a live, watched surface, so it gets its own
evidence rather than a claim.

- [x] `SearchEventController::store` is **not modified** and keeps writing
      `source = null`.
- [x] `stats`, `queries` and `export` gain `->whereNull('source')` behind
      `Schema::hasColumn('search_events','source')` (§9) — so **before** the
      migration they behave exactly as today, and **after** it they return
      exactly today's rows. The historical series is byte-identical either way.
- [x] `MetricsUnchangedTest` — seed the existing event types, snapshot
      `/admin/ai-search/stats` and `/admin/ai-search/queries`, then insert
      `source='live_engine'` rows and assert **both responses are unchanged**.
      That is the regression that matters: a shifted dashboard is the kind of
      breakage nobody notices for a week.

#### 14.4 Rollback / disable evidence

- [x] `DisabledFlagTest` doubles as the rollback drill: with the flag off, every
      route is honest-503/404, **no row is written, no job is queued, no
      conversation is touched**, and the reaper does nothing. Asserted, not
      assumed.
- [x] `SchemaGuardTest` — with `live_shopping_sessions` absent (simulating the
      deploy window before migrations run), read paths return the honest 503
      rather than 500. This is CLAUDE.md's "gap between code and migrations"
      rule, which has bitten this repo before.
- [x] **Disabling is the rollback.** Unsetting one env var turns the feature off
      without a redeploy of code and without touching data: sessions stop being
      created, the webhook 404s, and existing rows sit inert. No migration needs
      reversing to make production safe — which is the property to preserve if
      any of this is ever revised.

## Explicitly NOT in P1

Lease FSM and instruction/cancel routes (P3/P2) · multi-store and thumbnails
(P2) · `LiveShoppingPanel.vue`, the `GALLERY_TOOLS` / `TOOLS_WITH_LOADER`
registrations for **both** `tool-live_results` (gallery) and `tool-live_verify`
(loader/handle), and the `live_verify` tool itself — all **frontend repo**,
gated on its own approved `tasks/todo.md` (P4) · purchase-request bridge (P5) · a
`live_search` MCP tool · every SFU/TURN/media concern, which never touches
Laravel.

## Independent verification against current code (2026-08-31)

Every claim in this plan was re-checked against the repo as it stands. What
held, and what did not.

### Confirmed correct

- **Tool-name-agnostic product pickup.** `ConversationController::deriveProducts`
  (declared `:159`, loop `:164-172`) reads `$part['output']['products']` with no
  reference to the part `type`. Renaming the terminal part to
  `tool-live_results` therefore costs the backend nothing.
- **`active_slot` over a filtered index.** MySQL has no partial unique indexes,
  and the repo has **no `.env` at all** (only `.env.example`), while
  `config/database.php:19` defaults `DB_CONNECTION` to **sqlite** and
  `phpunit.xml` sets only `DB_DATABASE`. So the suite really does run on a
  different engine than production, and the generated-column variant really
  would be untested. Option 2 stands.
- **Constant-time comparison idiom exists.** `ProductIndexController:43` uses
  `hash_equals` with the "a plain === leaks the secret one byte at a time"
  comment the plan cites. Also confirmed: that file returns **503 on an unset
  secret** — the same "unset = off, not open" posture §10 asks for.
- **Ownership idiom exists.** `ConversationController::authorizeOwner` is
  `abort_unless($conversation->user_id === $request->user()->id, 403, 'Not your
  conversation.')` — copyable verbatim, and §11's `403 'Not your session.'`
  matches its shape.
- **`conversation_id` FK pattern** is exactly as cited in
  `2026_07_26_000000_add_conversation_id_to_purchase_requests_table.php`
  (`nullable()->after('user_id')->constrained('conversations')->nullOnDelete()`).
- **A queue worker really runs.** `supervisord.conf` has
  `queue:work --queue=high,default`, `config/queue.php` defaults to `database`,
  and `2025_08_06_211812_create_jobs_table.php` exists. The 202-then-process
  design is deliverable. Dispatch on the default queue (nothing in `app/` uses
  `onQueue` today; do not invent `high` for this).
- **The scheduler runs too** (`supervisord.conf` `[program:scheduler]`,
  `routes/console.php` uses the `Schedule` facade) — which is what makes the
  reaper in gap G1 below cheap.

### Corrections folded into the plan above

- **§9's "admin dashboard queries are untouched" was false.** The aggregates
  segment by `type`, not by source, so a `type='search'` row with
  `source='live_engine'` shifts every series. §9 now carries the
  `whereNull('source')` fix, guarded by `Schema::hasColumn` the way `broadened`
  already is.
- **§8 omitted the `parts` wrapper.** `content` is cast `array` on
  `ConversationMessage` and `deriveProducts` reads `$m->content['parts']` — the
  row must be `content = ['parts' => [ … ]]`, not a bare list.
- **§8 omitted `last_message_at`.** `addMessages` bumps it and the sidebar
  selects it; a live result that skips the bump never reorders the thread.
- **Terminal part renamed** `tool-live_verify` → **`tool-live_results`**, with
  `live_verify` demoted to the session-handle part (frontend-written,
  `{sessionId, status}`, and explicitly **no** `output.products`).

### Contract gaps — all resolved by codex-main, 2026-08-31

Every gap below is now decided and folded into the plan body above. Kept here as
the record of *why* each clause exists, so nobody simplifies one away later.

**Original statement of the gaps:**

- **G1 (blocking, design). A stuck `pending` session permanently locks the
  user out.** `active_slot` is claimed at create and released only by a terminal
  transition. P1 has no cancel route, no lease column and no TTL — all
  deliberately excluded. So if the PHP process dies between INSERT and the engine
  reply, or the engine simply never delivers a terminal webhook (user closes the
  tab, engine crashes), that user can never start another session and the only
  remedy is DB surgery. The one-active-session constraint needs a release path
  that does not depend on the engine's goodwill. Cheapest fix that keeps P1
  honest: a scheduled reaper in `routes/console.php` —
  `pending|running` older than N minutes → `failed`, `active_slot = null`,
  `error = 'timed out'` — with N config, and the engine contract additionally
  required to deliver a terminal event for every session it accepts. Without one
  of these, ship the constraint and the deadlock together.
- **G2 (blocking, contract). The ticket endpoint has no engine call defined.**
  §1 specifies only `POST {base_url}/sessions`. But `GET .../ticket` returns
  `sse_url`, `whep_url` and `iceServers` — Laravel cannot know a TURN credential
  or an SFU URL, so this must be an engine round-trip (proposed:
  `POST {base_url}/sessions/{engine_session_id}/tickets` with the service-secret
  bearer, replying `{ ticket, expires_at, sse_url, whep_url, iceServers }`), or
  else the ticket is a locally-signed token whose claim set and verifying key
  must be written down here. Pick one and specify it; §11 then also needs the
  ticket path's 503 for an unreachable engine, which it currently only covers
  for create.
- **G3 (blocking, deploy). `config('app.url')` is not a safe callback base.**
  `config/app.php:55` defaults it to `http://localhost` and `.env.example` ships
  `APP_URL=http://localhost`. A production box that never set `APP_URL` would
  hand the engine a localhost callback and the terminal webhook would silently
  never arrive. Either add a dedicated
  `services.live_shopping_engine.callback_base` (env-only, no default), or fold
  "callback base parses as https and is not localhost" into the same
  configured-or-off check as `base_url`/`service_secret` in §10.
- **G4 (contract). `running` is unreachable in P1.** Nothing sets it: the create
  path writes `pending`, and the only inbound event is terminal. Either state
  that the create path maps the engine's echoed `status` into
  `pending|running`, or drop `running` from the P1 status set and keep it as a
  P2 addition. Right now `scopeActive()` and the terminal-transition guard both
  name a state nothing produces.
- **G5 (test realism). `phpunit.xml` pins `QUEUE_CONNECTION=sync`.** So
  `ResultJobIdempotencyTest`'s "the duplicate dispatched while the first is in
  flight" cannot be written as stated — under `sync` the first job completes
  before the second dispatches. Restate it as what actually proves the property:
  pre-insert a receipt row with the same delivery key (now `delivery_id`, see the
  EngineV1 freeze below), then run the job and assert
  zero appends; plus run the same job twice and assert exactly one message and
  one `SearchEvent` by count. Related: **SQLite ignores `FOR UPDATE`**, so
  `lockForUpdate()` is a no-op under test — the unique delivery-key index is the
  property the tests actually exercise, and the plan should say so rather than
  implying the lock is covered.
- **G6 (minor, cross-repo). The frontend must register two names, not one.**
  `tool-live_results` in `GALLERY_TOOLS`, `tool-live_verify` in
  `TOOLS_WITH_LOADER`. Noted in "Explicitly NOT in P1", but it is the half of
  the rename that can silently regress — a terminal part nothing renders looks
  identical to an engine that never answered.
- **G7 (minor). `deriveProducts` caps the registry at the last 80 products and
  dedupes by FNV-1a hash of the URL.** The §8 bound of 24 per delivery is
  therefore a per-message bound, not a conversation bound, and repeated live
  sessions in one thread will evict older products from the rail. Expected, but
  worth stating so it is not later filed as a bug.


### Resolutions (codex-main, message e32e9d49)

- **G1 → §2 + §7b.** The engine issues `expires_at` on the accepted create
  response; Laravel persists it and reconciles every minute. No blind
  `created_at` timeout — the engine owns the lifetime and enforces the same
  deadline itself when healthy. Late terminal webhooks land on an absorbing
  terminal state: receipt recorded, nothing appended. Four tests: crash before
  the engine responded, accepted-but-no-terminal expiry, late webhook, and the
  reaper/webhook race.
- **G2 → §1 + §6 + §11.** Service-auth `POST {base_url}/v1/sessions/{engine_session_id}/viewer-tickets`,
  called per ticket request, reply (`≤60s`) passed through verbatim. Unreachable
  or incomplete → honest 503. Laravel never invents media or TURN details.
- **G3 → §1 + §10, and then SUPERSEDED the same day.** First decision: a
  dedicated env-only `LIVE_SHOPPING_CALLBACK_BASE`, validated as a canonical
  public https origin. **Final decision (message a161991a): remove it.** The
  engine's deployment config maps a fixed `callback_id` (`boxly-p1`) to the
  canonical public HTTPS `/live-shopping/webhook` destination and validates
  readiness, so Laravel never constructs or transmits a callback URL at all.
  This is strictly better than the first answer — it deletes the outbound URL
  rather than validating it, which is the only way to be sure the
  `config('app.url')` trap cannot come back — and it removes the last piece of
  URL-construction hygiene this repo would have had to own. The residual cost is
  recorded in §10: Laravel can no longer detect a wrong mapping, so §7b's
  expiry is what surfaces it, and the first live session should be checked
  end-to-end rather than assumed.
- **G4 → §4.** `running` stays and becomes reachable: `pending` before the engine
  call, `running` once the engine confirms **durable** acceptance.
- **G5 → §12.** The sequential duplicate + pre-inserted-receipt tests are the
  real ones; the plan now states plainly that SQLite does not exercise row-lock
  behaviour and that the unique receipt index is the concurrency arbiter. An
  optional MySQL gate is documented — the repo does support it
  (`docker-compose.yml` ships MySQL 8.0 with sail's testing-database script).
- **G6 → closed.** Covered by the approved Nuxt plan, which registers
  `tool-live_results`.
- **G7 → documented as expected behaviour**, not a defect.
- **Field spelling:** snake_case at the HTTP boundary in both directions
  (`session_id`, `expires_at`, `ice_servers`, `sse_url`). Final once Sol returns
  the engine contract; if it differs, this file changes, not the controller.

### EngineV1 freeze — old → new (codex-main, message 5b0cb407)

Every legacy shape is gone from this file. Grep evidence is in the REVIEW_READY
for this revision.

| Where | Old | Frozen EngineV1 |
|---|---|---|
| Our create request | `store: {id, origin}` | `store_id` (flat); origin **removed** |
| Our create request | `conversation_id` optional | `conversation_id` **required + owned** |
| → engine create | `{objective, store, callback_id}` | `{schema_version, conversation_id:String, store_id:String, query, callback_id}` |
| ← engine create | `{session_id, status, expires_at}` | `{ok, data:{schema_version, session:{id, conversation_id, store_id, status, latest_seq, created_at, expires_at}}}` |
| → engine ticket | empty body | `{schema_version, user_id:String, scopes:["events:read","media:read"]}` |
| ← engine ticket | bare five fields | wrapped `{ok, data:{schema_version, …five fields}}` |
| Webhook body | `{event_id, session_id, status, error, products}` | `{schema_version, delivery_id, session_id, terminal_seq, conversation_id, occurred_at, result:{outcome, products, error_code}, assistant_part}` |
| Idempotency key | `event_id` | `delivery_id` |
| Product shape | `{url,title,price,store,image}` | **ProductV1** `{store, store_id, title, url, image, current_price, list_price, availability, observed_at}` |
| Terminal driver | `status` / `error` | `result.outcome` / `result.error_code` |
| Sessions column | `terminal_event_id`, `error` | `terminal_delivery_id`, `error_code`; **+`latest_seq`, `terminal_seq`** |
| Webhook ack | `202` (body unspecified) | `202` with exactly `{ok:true, data:{schema_version:1, delivery_id, accepted:true}}` |
| Webhook auth | two headers, HMAC over `"{ts}.{body}"` | 5 headers (`X-Boxly-Key-Id`/`-Timestamp`/`-Nonce`/`-Content-SHA256`/`-Signature: v1=…`), signed over `"v1\n"+ts+"\n"+nonce+"\n"+body_sha256` — §1.5, **frozen** |
| Product money | `price`/`was` strings | `current_price`/`list_price` **money objects**; read-path adapter, §8.1 |
| Receipt row | id + outcome | **durable inbox**: +`content_sha256`, +`payload`, +`status` (`received`→`processed`) |
| Webhook accept | SELECT-then-decide (racy) | `insertOrIgnore` decides; loser reads the winner in-transaction → deterministic 202/409 |
| Job input | the delivered body | **receipt id only**; drainer (§7c) guarantees processing |
| Assistant part | composed by Laravel | **supplied by the engine**, checked against `result.products`, then bounded |

Two consequences carried into the plan rather than left implicit: the
`StoreOriginValidationTest` is deleted (no origin exists to validate), and
ProductV1 does not render in the existing rail without the §8.1 fix.


---

## Review

### What was built

19 new files, 6 modified. Laravel is the control plane and nothing else: it
creates a session, reports it, mints viewer tickets by asking the engine, and
applies exactly one terminal delivery per session. It never proxies, terminates
or sees video, and it never invents a ticket, a session id or a deadline.

**New** — `LiveShoppingController` (create/show/ticket), `…WebhookController`
(terminal delivery), `LiveShoppingEngine` (+`…Exception`) as the only thing that
talks to the engine, `LiveShoppingSignature` (both canonical strings),
`ProductV1` (strict inbound bounding + read-path money), `LiveShoppingSession` /
`…WebhookReceipt` models, `ProcessLiveShoppingResultJob`, the `reconcile` and
`drain` commands, 3 migrations, 17 test files. Green on BOTH drivers: SQLite
`OK (169 tests, 512 assertions)` (8 gate tests skipped) and MySQL 8.0.32
`OK (167 tests, 538 assertions)`.

**Modified** — `routes/api.php` (4 throttled routes), `routes/console.php` (2
scheduled sweeps), `config/services.php` (env-only, off by default),
`SearchEvent` (`source` fillable), `SearchEventController` (segment the admin
aggregates), `ConversationController` (render ProductV1 money in the rail).

### The five things that actually matter

1. **Two different HMACs, not a bearer token** (§1.5/§1.6). Outbound binds
   METHOD and PATH as well, so a signed create body cannot be replayed at
   `/cancel`. The body is serialized once and those exact bytes are both hashed
   and sent.
2. **The create/reaper race is settled by a compare-and-set, not a `save()`.**
   A pending row with no deadline can legitimately be reaped while the create is
   in flight; a blind save would then write `running` back over a released row
   and produce a live session *outside* the one-active-session constraint. On a
   lost CAS we never resurrect locally — we ask the engine to cancel and return
   a stable 409.
3. **An unknown `session_id` on a webhook is not an orphan.** A fast engine can
   deliver terminal before our create has stored `engine_session_id`. Young
   receipts stay `received` and retryable; only past the orphan horizon do they
   fail, with evidence.
4. **The 202 is a durability promise.** It is not returned until the receipt has
   committed, and the drainer — not the dispatch — is what keeps the promise.
5. **Rejection over repair, everywhere.** A malformed product, a divergent
   `assistant_part`, an over-cap list, a numeric-string price: all reject the
   whole delivery. Silently repairing would hide a broken engine and change what
   the customer is shown.
6. **ProductV1 is frozen identically in three repos** (§1.4 table). The exact
   9-key set, uppercase-only currency and calendar-checked UTC timestamps exist
   so engine, Laravel and Nuxt cannot drift into three validators that are each
   independently green and mutually incompatible — which is what had already
   happened when this was caught.

### Corrections made to this plan during implementation

The plan was wrong in five places and now says so at each one, rather than
quietly matching the code:

- §1.2/§1.3 specified `Authorization: Bearer` — withdrawn for the §1.6 HMAC.
- §1.3 specified `GET` for the ticket route — now POST, throttled.
- §12 specified *truncating* over-cap products — now rejects the delivery.
- §1.1 did not name the two ID domains; the frontend handle must carry
  `localSessionId` and `engineSessionId` separately, and `PublicContractTest`
  now locks all three public envelopes.
- §1.4's ProductV1 was under-specified (nullable `availability`/`observed_at`,
  title ≤255, URL ≤2000, loose timestamps, fragment-tolerant images) and had
  already produced three incompatible validators across the repos. Now a frozen
  table, implemented identically in all three.

### What the first real test run caught

Worth recording, because both would have shipped on a "reviewed, looks right"
judgement:

- `ShowSessionTest::session()` collided with the framework's own `session()`
  helper — a **fatal**, not a failure, so it took the whole suite down.
- `ProductV1::utcRfc3339` leaned on `strtotime()` to reject impossible dates.
  It does not: `strtotime('2026-02-30T00:00:00Z')` rolls forward to March 2. A
  malformed timestamp would have been stored as a plausible-looking fact. Now
  checked with `checkdate()` and explicit hour/minute/second ranges.

### What the MySQL gate caught — a production-breaking bug

`tasks/live-shopping-mysql-gate.md` has now **run and passed** (MySQL 8.0.32,
`OK (167 tests, 538 assertions)`), and it earned its keep immediately.

The CAS in `LiveShoppingController::store()` wrote the engine's RFC3339
`expires_at` string directly. A query-builder update does not run Eloquent's
`datetime` cast, so the raw string reached the driver — **SQLite stored
`"2026-09-01T12:34:56Z"` without complaint; MySQL rejected it with 1292**. Every
successful create would have been a 500 in production, with this entire suite
green. 14 of the 15 pre-fix failures came from that one line.

Fixed by parsing to a `Carbon` at the write site. Pinned by
`test_the_engine_deadline_persists_as_a_real_datetime`, which asserts the
persisted value and its SQL-comparability (which the reconciler depends on)
rather than just a 201 — so it fails on either driver if this comes back.

The general lesson, worth keeping: **a green suite on a dynamically-typed
database is not evidence about a statically-typed one.** SQLite will accept
almost any string into any column.

### Open, and deliberately so
- **`QueueTransactionalityTest`** stays optional: the drainer already closes the
  window it would measure.
- **Migration `down()` on MySQL** is still unverified — the gate drops tables
  directly between tests rather than migrating down.
- **Genuine two-process contention** is still unproven. The gate proves the
  primitives (a real 1062, a real 1205 lock wait), and the interleaving logic is
  covered by tests, but nothing runs two requests at literally the same moment;
  the manual two-request check in the gate doc remains the honest end-to-end
  proof.
- **§13's deploy probes** are unrun by design — nothing here has been committed,
  pushed or deployed.

### Not touched

The two unrelated legacy findings (an old migration and a cosmetic issue)
surfaced during review are deliberately out of this slice. Instructions, cancel,
leases and multi-store remain P2/P3.
