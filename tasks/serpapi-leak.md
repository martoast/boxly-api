# SerpAPI credit leak — the hourly product-index cron

**Opened 2026-08-06.** Cron is disabled in prod; the underlying faults are not fixed.

## What happened

The SerpAPI account (Developer plan, 5,000 searches/month, renews Aug 23) hit
**4,519 / 5,000 used with 481 left** — with barely any real customer traffic.

Measured on the live account, nobody on the site:

```
this_hour_searches: 17
last_hour_searches: 45
```

**45 searches/hour ≈ 1,080/day.** The plan is 5,000/month. Almost the entire
month's allowance went to a background job in the ~2.5 days since it shipped
(`dab8334`, 2026-08-03).

## Ruled out

- **`/app/admin/ai-search` does NOT cost credits.** It calls
  `/admin/ai-search/stats`, `/admin/ai-search/thread/{id}`
  (`SearchEventController` — no outbound HTTP at all) and `/api/intent-map`
  (code-only clustering, explicitly no AI). Drawer thumbnails are stored URLs
  replayed from the saved message payload, never re-resolved. Opening the page
  repeatedly is free. This was the original suspicion and it was wrong.

## Root cause

`Schedule::command('boxly:index-warm --limit=25 --max-age=6')->hourly()` in
`routes/console.php`, running live in prod (`supervisord.conf:29` loops
`schedule:run` every 60s; `PRODUCT_INDEX_SECRET` is set — the Nuxt
`/api/shopper/queries` probe returns 403, not the 503 it gives when unset).

Each product costs **3 SerpAPI searches**: `WarmProductIndex::warmUpstream()`
asks Nuxt for the query list and gets back `base`, `base used`, `broader`
(`app/server/api/shopper/queries.post.ts:58`), then fires each at
`/products/search`. Each is a distinct `multiShopping` cache key = 1 search.

**25 products × 3 = up to 75/hour.** Observed 45 (some dedupe to fewer than 3).

Three compounding faults:

1. **`warmUpstream()` is unconditional and runs before the index is consulted.**
   `WarmProductIndex.php:171` warms; `:173` then calls the panel, which may
   answer `cached: true`. The run summary counts those as
   `"already fresh (free)"` — they were **not** free, 3 searches were already
   paid. This is precisely why the spend never appeared in the logs.
2. **The warm stage never filters the work list against `ProductIndex`.**
   `WarmProductIndex.php:110-132` pulls the most-recent distinct
   `purchase_request_items` URLs and takes the first N with no
   already-indexed check. The comment says *"over-fetch; many will already be
   indexed"* — the filter that implies was never written. So it re-warms the
   **same products every hour, forever**.
3. **The SerpAPI result cache TTL is 30 minutes**
   (`ProductExtractController.php:1837`), so an **hourly** job misses on every
   single pass by construction.

## Done

- [x] Diagnose — confirmed against the live SerpAPI account, not inferred
- [x] Clear the admin page as a suspect
- [x] **Disable the cron** in `routes/console.php` (commented out, with the
      full reasoning inline so nobody uncomments it blind). `schedule:list` now
      reports no scheduled tasks — this was the only one.
- [x] Update `SERPAPI_KEY` in local `api/.env` to Alex's rotated key

## Blocked on Alex — do these in order

- [ ] **Set `SERPAPI_KEY` in the DigitalOcean app env** for the API component.
      The old key (`db2321…`) is **dead** — SerpAPI returns
      `Invalid API key`. Until this is set, **product search is broken in
      production**: the concierge's `search_products`, the Shopper extension
      panel, and starter-card images all fail. There is no `.env` on that box
      and no `doctl`/DO token available locally, so this cannot be done from
      here.
      **Deploy the cron-disable first**, or the remaining 481 credits are gone
      within ~11 hours of the key going live.

## To patch properly, before the cron can ever be re-enabled

- [ ] **Make `warmUpstream()` conditional.** Ask the index first; only warm on a
      genuine miss. Nothing that would answer `cached: true` should cost a
      search.
- [ ] **Filter the warm work list against `ProductIndex`** so an already-indexed
      product is never re-warmed. Write the filter the `$room * 4` over-fetch
      comment already assumes exists.
- [ ] **Make the run summary honest** — count searches actually spent, not
      panel cache-hits reported as "free". A silent cost is how a cron quietly
      becomes a bill (the docblock says this; the code doesn't do it).
- [ ] **Re-cadence:** move to daily, and set `--max-age` comfortably above the
      30-min search cache TTL so refreshes stop colliding with their own cache.
- [ ] **Add a hard monthly spend ceiling** — a counter that makes the command
      refuse to start once it has spent N searches this billing cycle. The plan
      resets on the 23rd. This job should never again be able to consume the
      whole allowance unattended.

## Separate exposure — worth closing

`/products/search` is **fully public, unauthenticated**, `throttle:30,1` per IP
(`routes/api.php:114`). One bot at the limit = ~43k searches/day, ~9x the
monthly plan in 24 hours. Not the cause of this incident, but it is an open tap
on a metered API.

- [ ] Gate it behind auth, or drop the throttle hard and scope it to our own
      origins.

## Review

Change made this session is deliberately minimal: **`routes/console.php` only** —
the schedule block commented out with the diagnosis inline. No behaviour in
`WarmProductIndex`, the panel, or the search path was touched, so nothing that
currently works can break. The command still runs fine by hand
(`php artisan boxly:index-warm --dry`) for whoever picks up the patch.
