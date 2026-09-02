# Live Shopping P1 — MySQL evidence gate

**Status: RUN AND PASSED — 2026-08-31.** `OK (167 tests, 538 assertions)` against
real MySQL 8.0.32, plus `OK (8 tests, 28 assertions)` for the MySQL-only
`MysqlGateTest`. SQLite remains green at 169 tests (the 8 gate tests skip there,
by design).

## ⚠️ It found a production-breaking bug. That is the headline.

`LiveShoppingController` wrote the engine's RFC3339 `expires_at` string straight
into the CAS update. That update is a **query-builder** update, so Eloquent's
`datetime` cast never runs and the raw value goes to the driver:

- **SQLite** is dynamically typed and stored `"2026-09-01T12:34:56Z"` happily.
- **MySQL** rejected it: `SQLSTATE[22007] … 1292 Incorrect datetime value`.

So **every successful create would have been a 500 in production**, while the
entire SQLite suite stayed green. 14 of the 15 pre-fix failures traced to this
one line. Fixed by parsing to a `Carbon` at the write site, and pinned by
`CreateSessionTest::test_the_engine_deadline_persists_as_a_real_datetime`, which
asserts the persisted VALUE (and that it is SQL-comparable, which the reconciler
depends on) rather than just a 201 — so it fails on either driver if this
returns.

This is precisely the class of bug the gate was written to catch, and it would
not have been caught any other way.

## Provenance of the run

| | |
|---|---|
| image | `mysql/mysql-server:8.0` @ `sha256:d6c8301b7834c5b9c2b733b10b7e630f441af7bc917c74dba379f24eeeb6a313` |
| server | MySQL **8.0.32**, InnoDB |
| container | `boxly-ls-mysql-gate`, `127.0.0.1:13306` → 3306, tmpfs `/var/lib/mysql` |
| PHP | 8.3.31 CLI, `/home/alex/.cache/boxly-php-ci/php-linux-x86_64` |
| git | worktree at `64afdb1`, uncommitted — nothing committed, pushed or deployed |

Deliberately **not** `sail`/`docker compose`: `codex-sol-local-stack` was running
its own Sail stack from this same repo, and sharing the compose project, container
names and ports 80/3306 would have had us fighting over them. Sol took 13307.

```bash
docker run -d --name boxly-ls-mysql-gate \
  -e MYSQL_ROOT_PASSWORD=gatepass -e MYSQL_ROOT_HOST=% -e MYSQL_DATABASE=testing \
  -p 127.0.0.1:13306:3306 --tmpfs /var/lib/mysql:rw,size=1g \
  mysql/mysql-server:8.0 \
  --innodb-flush-log-at-trx-commit=0 --sync-binlog=0 --skip-log-bin \
  --innodb-doublewrite=0 --innodb-flush-method=nosync

DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=13306 DB_DATABASE=testing \
DB_USERNAME=root DB_PASSWORD=gatepass LIVE_SHOPPING_ANNOUNCE_CONNECTION=1 \
LIVE_SHOPPING_MYSQL_GATE=1 \
/home/alex/.cache/boxly-php-ci/php-linux-x86_64 vendor/bin/phpunit \
  --filter 'LiveShopping|ProductV1'
```

The durability flags and tmpfs are **test-only speed**, not a schema change:
without them each `ALTER` in the migration chain waits on a real fsync and one
test took over 40 seconds. With them the whole suite runs in ~14s. They change
crash-safety, which a disposable container does not need, and nothing else.

`LIVE_SHOPPING_ANNOUNCE_CONNECTION=1` makes each test print its active
connection, so a run cannot silently prove nothing (see the trap below).

**The container is disposable.** `docker rm -f boxly-ls-mysql-gate` when done.

## Why this gate exists

Two mechanisms carry the entire correctness argument for this feature, and
**neither is exercised by the SQLite suite**:

1. **`UNIQUE(user_id, active_slot)`** — the one-active-session constraint. Every
   "you already have a session running" answer, and the whole no-precheck race
   argument, rests on the database refusing the second insert.
2. **`lockForUpdate()`** — used in `ProcessLiveShoppingResultJob`,
   `LiveShoppingReconcile` and the receipt transition. **SQLite ignores
   `FOR UPDATE` entirely** — it is a silent no-op there. So the tests prove the
   unique indexes arbitrate; they do not prove the row locks do.

The tests that pass today prove the *logic*. This gate proves the *engine*
underneath it behaves the way that logic assumes.

## How to run it

`docker-compose.yml` already ships `mysql/mysql-server:8.0` plus sail's
`create-testing-database.sh`, so no new infrastructure is needed:

```bash
./vendor/bin/sail up -d
./vendor/bin/sail exec -e DB_CONNECTION=mysql -e DB_DATABASE=testing \
    -e LIVE_SHOPPING_MYSQL_GATE=1 -e LIVE_SHOPPING_ANNOUNCE_CONNECTION=1 laravel.test \
    php artisan test --filter="LiveShopping|ProductV1"
```

**RESOLVED.** `LiveShoppingTestCase` defaults unconditionally to SQLite
`:memory:`. MySQL destructive setup requires both `DB_CONNECTION=mysql` and
the literal test-only opt-in `LIVE_SHOPPING_MYSQL_GATE=1`, then fails closed
unless the resolved database is exactly `testing` on an allowlisted local host.
It drops this suite's tables only after that guard passes and prints the active
connection/database when `LIVE_SHOPPING_ANNOUNCE_CONNECTION=1`.
**Still check that line in the output before trusting a pass** — a gate that can
quietly not run is worse than no gate.

## What must be observed, not just "green"

All in `tests/Feature/LiveShopping/MysqlGateTest.php`, which **skips loudly on
SQLite** so it can never pass by not running.

- [x] **Unique index, MySQL semantics.** Observed: a second active row raises
      `QueryException`, SQLSTATE `23000`, message containing `1062` and
      `active_slot`. `isActiveSlotCollision()` was invoked against that *real*
      exception (via reflection) and returned true, and the route answers **409**
      end to end. This was the single highest-value assertion in the gate: the
      classifier matches on driver message text, and a miss would have surfaced
      a routine collision as a 500.
- [x] **NULL non-collision.** Five terminal rows with `active_slot = NULL`
      coexist with one active row for the same user.
- [x] **Row locks actually block.** A genuinely separate PDO connection with
      `innodb_lock_wait_timeout = 1` cannot take a row held by
      `lockForUpdate()` — it raises **1205 lock wait timeout**. A second test
      confirms the lock is *released* on commit (a lock that never let go would
      deadlock the drainer rather than serialise it). On SQLite this pair is
      meaningless: `FOR UPDATE` is a silent no-op there.
- [x] **Column types survive.** Read from `information_schema`: `expires_at` is
      `timestamp`; `latest_seq`/`terminal_seq` are **unsigned** `bigint`;
      `payload` is `json`; `content_sha256` holds ≥64 chars. Plus a live
      round-trip: nested JSON, a `4294967296` sequence (past 32 bits, on
      purpose), and a timestamp.
- [x] **Load-bearing indexes exist**, read back via `SHOW INDEX`:
      `UNIQUE(user_id, active_slot)`, `UNIQUE(engine_session_id)`,
      `UNIQUE(delivery_id)`.
- [x] **Migrations run on MySQL.** The full chain runs per test against real
      MySQL — that is how every test in the suite gets its schema.

### Observed MySQL behaviour worth knowing

**A native `JSON` column does not preserve object key order.** MySQL stores JSON
objects in a sorted binary format, so `{"nested":…,"list":…}` comes back as
`{"list":…,"nested":…}`. SQLite, which stores the raw text, does preserve order.
Harmless here *only* because nothing reads the stored payload positionally — the
job accesses it by key, and the `assistant_part` ⇄ `result.products` agreement
check runs on the **request bytes** before persistence, never on the stored
copy. Anything that ever byte-compares a stored payload would break on MySQL.

### Not covered by this gate

- **Reconciler under genuine contention** — the reconciler-vs-create
  interleavings are covered logically in `CreateReaperRaceTest` and
  `ReconcileTest`, and those now run green on MySQL, but nothing here runs two
  real processes at once. The row-lock tests above prove the primitive they rely
  on; the manual two-request check below is still the honest end-to-end proof.
- **Migration `down()` / rollback** — not exercised. The tables are dropped
  directly between tests rather than migrated down, so `down()` remains
  unverified on MySQL.

## Genuine concurrency

Every "race" test in the SQLite suite simulates the other party by pre-inserting
the row it would have written. That proves the *response* to a lost race, which
is what matters most — but nothing in CI actually runs two requests at once.
Worth one manual pass under MySQL:

```bash
# two simultaneous creates for one user -> exactly one 201, one 409
seq 2 | xargs -P2 -I{} curl -sS -o /dev/null -w "%{http_code}\n" \
  -X POST https://<host>/live-shopping/sessions \
  -H "Authorization: Bearer <token>" -H "Accept: application/json" \
  -d '{"conversation_id":1,"objective":"x","store_id":"on"}'
```

Expect exactly one `201` and one `409`. **Two 201s means the constraint is not
doing its job** and the feature must stay disabled.
