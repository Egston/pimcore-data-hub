# Persistent Cache Flow

A reader-friendly walkthrough of how the DataHub persistent (SWR) cache
behaves. Read this first; companion document `Persistent-Cache-Architecture.md`
maps the same concepts to specific classes, methods, and config knobs.

## What the persistent cache does

DataHub serves GraphQL responses. Re-running a GraphQL query end-to-end
(query parser → resolver → Pimcore → DB) is expensive — measured worst
case in the order of minutes for large listing queries (e.g. the
Resource Library listings across all languages).

The persistent cache stores the rendered JSON response per
`(client, canonical-query-body)` pair and serves it from Redis on
subsequent requests. The "SWR" part — *stale-while-revalidate* — means
clients never wait for a refresh: a stale response is served immediately
and a background refresh is triggered behind the scenes.

There are two refresh-trigger paths:

1. **Read-triggered (passive).** A request comes in, finds a stale
   entry, gets served the stale response, and *that same request* (or
   a Messenger worker) re-runs the resolver afterwards to write a
   fresh entry.

2. **Invalidation-triggered (active).** Pimcore fires an update/delete
   event for a DataObject / Document / Asset. A listener walks a
   reverse index from the affected tag back to every cached query that
   depends on it, and proactively schedules each one for refresh.

Both paths converge on the same dispatch mechanism (Symfony Messenger
queue → background worker).

## The two cache layers

```
GraphQL request
    │
    ▼
┌─────────────────────────────────────────────┐
│ Standard output cache (short TTL, ~30s)     │  ← request-level cache,
│                                             │    shared with the rest
│   ┌─────────────────────────────────────┐   │    of Pimcore. Optional.
│   │ Persistent (SWR) cache              │   │
│   │   - Long TTL (default payload 24h,  │   │  ← long-lived, SWR
│   │     classifier may override)        │   │    semantics, the focus
│   │   - per-query meta + payload        │   │    of this document
│   │   - per-tag reverse index           │   │
│   └─────────────────────────────────────┘   │
└─────────────────────────────────────────────┘
    │
    ▼
GraphQL resolver (the expensive part)
```

The standard output cache is a thin per-request layer. When the
persistent cache is enabled the workspace also enables
`persistent_disable_output_cache_for_guarded` so the standard layer
becomes a no-op for cached operations — no double storage. From here on
this document is exclusively about the **persistent** layer.

## States a cached entry can be in

Each `(client, query)` pair is in one of four states:

| State | Meaning |
|---|---|
| **MISS** | Nothing in Redis. Request runs end-to-end, response gets written. |
| **HIT** | Entry exists and is fresh. Response served from cache. |
| **STALE** | Entry exists but a downstream invalidation has happened since it was written. Response served from cache *and* a background refresh is triggered. |
| **(in-flight)** | A refresh is currently running for this entry. New invalidations during this window get coalesced into a single trailing refresh. |

Freshness is determined by comparing the entry's `refreshedAt`
timestamp against a global invalidation watermark — see
*Determining freshness* below.

## The three Redis keys for one cached query

For each cached `(client, query)` pair, three keys live in Redis:

```
                        sha256("client:<client>\n<canonical body>")
                                         │
                ┌────────────────────────┼─────────────────────────┐
                ▼                        ▼                         ▼
        persistent_output_         persistent_output_      datahub_enqueue_
        payload_<hash>             meta_<hash>             req_<hash>
                                                            (only while a
        the JSON response          { refreshedAt,            refresh is
        body                         client,                 queued or
                                     operation,              in-flight)
                                     canonical,
                                     tags, ... }
```

Plus one optional sibling that only appears when invalidations stack
during an in-flight refresh:

```
        datahub_pending_refresh_<hash>     "remember to fire one more
                                            refresh after the current one"
```

### `persistent_output_payload_<hash>`

- **What it stores:** the JSON response body.
- **Set by:** the postHandle hook on a successful fresh response
  (write happens via `savePersistent`).
- **TTL:** controlled by `payloadTtl` — 24h by default, 14d for listing
  queries, may be overridden per-operation via `ttl_override`.
- **Released by:** TTL expiry, or `clearAll()` on bundle-level reset.
- **Repainted (TTL bumped without re-running the resolver) when:** a
  HIT crosses the payload's TTL half-life, to keep hot queries from
  losing their payload while their meta is still alive.

### `persistent_output_meta_<hash>`

- **What it stores:** a small associative array with `refreshedAt`,
  the client name, operation name, canonical request body,
  `payloadSavedAt`, `payloadTtl`, and the tag list.
- **Set by:** `savePersistent` alongside the payload, and re-saved on
  every FRESH HIT (rolls `refreshedAt` forward).
- **TTL:** controlled by the `ttl` knob (typically shorter than the
  payload TTL; the meta naturally falls off while the payload survives
  on its own clock).
- **Released by:** TTL expiry, or `clearAll()`.

### `datahub_enqueue_req_<hash>` — the dedupe sentinel

- **What it stores:** the literal value `1` — its presence is the
  signal, the value carries no meaning.
- **Set by:**
  - the invalidation listener when it dispatches a refresh message;
  - the read-path terminate listener when it dispatches a refresh
    after serving a STALE response.
- **TTL:** `persistent_enqueue_dedupe_ttl`, currently **300 seconds**
  in production config. Per-operation overrides via
  `enqueue_dedup_ttl_override`. **Not renewed in flight** — the value
  written at dispatch time is the value that expires.
- **Released by:** the worker on **successful** refresh completion. On
  failure the sentinel is deliberately left to expire on its own, so
  Messenger retries don't pile up duplicate dispatches.
- **What it prevents:** dispatching a duplicate refresh message while
  one is already in flight for the same query. When the sentinel is
  present, the listener writes the pending flag instead of dispatching.

### `datahub_pending_refresh_<hash>` — the pending flag

- **What it stores:** the literal value `1`. Same shape as the
  sentinel; its presence is the signal.
- **Set by:** the invalidation listener when it finds the sentinel
  already present (i.e. an invalidation arrives while a refresh is in
  flight).
- **TTL:** `max(enqueue_dedupe_ttl × 10, 600)` — auto-derived from the
  sentinel TTL. Currently 3000 seconds (50 minutes) with the 300s
  sentinel.
- **Released by:** the worker reads it on refresh completion. If set,
  the worker:
  1. removes the pending flag,
  2. removes the sentinel,
  3. dispatches a **single** trailing refresh message — no matter how
     many invalidations bumped the flag during the in-flight window.
- **What it prevents:** losing invalidations that arrive while a
  refresh is mid-flight. The current refresh started reading data
  *before* those invalidations landed, so its written payload would
  already be stale on commit. The trailing refresh covers them all.

### `datahub_graphql_op_cooldown_<hash>` — the invalidation-cooldown sentinel

- **What it stores:** the literal value `1`. Same shape as the dedupe
  sentinel and pending flag; presence is the signal.
- **Set by:** the invalidation listener when it handles the first
  invalidation in a window for an operation that carries an
  `invalidation_cooldown_ttl`. Armed *instead of* dispatching an
  immediate refresh — the listener instead dispatches a single dated
  refresh message (see *Invalidation cooldown* below).
- **TTL:** the operation's `invalidation_cooldown_ttl` (6h in
  production for the translation-verification listings). Tagged
  `TAG_WATERMARK`, not `TAG_COMMON`, so an SWR-layer `clearAll()`
  preserves it — otherwise a clear would orphan the queued dated
  refresh and let the next edit double-dispatch.
- **Released by:** the worker on successful completion of the dated
  refresh, opening a fresh window. On failure it is left to TTL-expire
  near its `deliverAt`.
- **What it prevents:** a full-listing refresh on every per-edit
  invalidation of an expensive batch-verification view. While the
  sentinel is present, further invalidations of the same entry are
  suppressed — a dated refresh is already queued for the window.

## Invalidation cooldown (per-operation refresh throttle)

Operations carrying an `invalidation_cooldown_ttl` (the coarse
translation-verification listings) take a throttled path on the
invalidation side. Operations with a coarse listing granularity carry
`granularity: list`, so a save on any contributing DataObject class
would otherwise invalidate the whole listing and trigger a full refresh
— once per edit. A translator marking items verified one-by-one would
drive a full refresh per click.

Instead, the first invalidation in a cooldown window:

1. arms the `datahub_graphql_op_cooldown_<hash>` sentinel (TTL = the
   cooldown window), and
2. dispatches a single `PersistentRefreshMessage` carrying an absolute
   `deliverAt = now + cooldown`.

The dated message sits in the priority queue, invisible, until its
`deliverAt` elapses; it then pops and refreshes the entry against
then-current data, reflecting every edit made during the window. The
worker clears the sentinel on success, opening the next window. Further
invalidations while the sentinel is live are no-ops — a dated refresh is
already queued.

This is a pure trailing-edge throttle: N edits in one window collapse to
exactly one refresh, fired `cooldown` after the first edit. There is no
periodic poller — the dated message *is* the timer; a window with no
edits queues nothing.

**The read path is unchanged for these operations.** The cooldown guards
only the invalidation→enqueue side. A targeted invalidation of a
cooldown op does not move any watermark, so reads continue to serve a
HIT carrying knowingly-slightly-stale data until the scheduled refresh
lands. The global-watermark fallback still stales these ops normally
when it fires.

## Determining freshness: the watermark

### What the watermark is

A single global Redis key:

```
datahub_graphql_fallback_watermark_ts → unix timestamp
```

In code: constant `KEY_FALLBACK_WATERMARK_TS`, mutator
`bumpFallbackWatermark()`, tag `TAG_WATERMARK`.

**It is a fallback marker, not a per-invalidation counter.** It
records the timestamp of the most recent invalidation event the
listener *couldn't* dispatch precisely via the reverse index. In
steady-state operation — every targeted invalidation handled by
per-query dispatch — the watermark stays put for long stretches,
only moving when the listener has to fall back. The four fallback
cases are enumerated in *When the watermark fires* below.

Every read in `preHandle` runs the freshness predicate against this
key:

```
fresh  iff  meta.refreshedAt >= fallbackWatermark
stale  iff  fallbackWatermark > 0 AND refreshedAt > 0 AND refreshedAt < fallbackWatermark
```

Because `fallbackWatermark` barely moves, this predicate almost
always returns FRESH. A targeted invalidation of object X does **not**
push the watermark forward, so a cached query that doesn't depend on
X is unaffected and stays FRESH on every read — exactly the behavior
we want.

STALE only arises when a fallback has fired *since the entry was last
written*. The watermark therefore implements a conservative
"something-untargetable-just-happened — mark everything older than
this timestamp as potentially affected" policy. It is not a
per-invalidation propagation mechanism; it is the safety floor that
fires when the precise path can't help.

### Why the watermark exists (the fallback role)

Cache invalidation runs on two parallel mechanisms, and the watermark
is the **fallback**, not the primary:

**Primary mechanism — per-query dispatch via reverse index.** When an
update fires on a DataObject, the listener walks the reverse index
(`taginx_<tag>`) and finds the exact list of cached queries that
depend on that object or class. It schedules a refresh message for
each, surgically. The watermark **is not bumped** on this path —
unaffected queries stay FRESH.

**Fallback mechanism — watermark bump.** When the primary can't
identify affected queries with precision, the listener bumps the
watermark. Every cached entry then turns STALE, every subsequent read
returns the stale response *and* schedules a refresh through the
read-triggered path (kernel.terminate listener). No invalidation
gets silently dropped, even when the primary path can't help.

### When the watermark fires

| Situation | Why per-query dispatch can't be used | Effect |
|---|---|---|
| Queue path disabled (`persistent_refresh_queue_enabled: false`) | The dispatch mechanism isn't wired | Every invalidation event bumps the watermark unconditionally |
| Non-element event (something other than DataObject / Document / Asset) | The listener can't extract an element → can't compute tags → can't walk the reverse index | Watermark bumped |
| Reverse-index lookup empty for all affected tags | No cached entries depend on this object — but a *future* write of a query that does depend on it must still see the invalidation | Watermark bumped |
| Exception thrown anywhere in the listener | Could be a Redis blip, a malformed entry, a class-load failure — we don't trust ourselves to know which queries are affected | Watermark bumped (best-effort in a nested try/catch) |

### What it costs to bump the watermark

A single bump cascades through the entire cache: every cached query
becomes STALE on its next read. Every reader then triggers a
read-path refresh dispatch (one per distinct query). If a popular
query is accessed by many concurrent clients in the brief window
after a watermark bump, the dispatch path absorbs the burst via the
sentinel — only one dispatch lands, the rest see "sentinel present"
and skip — but the dispatch volume can still be substantial under
high traffic.

This is why the primary path is preferred: a per-query dispatch
only schedules N refreshes (where N = queries that actually depend on
the changed object). A watermark bump schedules one refresh per
*read* of any cached entry until they're all refreshed.

### What it preserves through bundle-level clears

`clearAll()` drops every payload + meta + index entry tagged with
`TAG_COMMON`. It **does not** clear the watermark — that lives under
its own dedicated tag (`TAG_WATERMARK`).

This matters: if `clearAll()` *did* clear the watermark, every
freshly-written entry after the clear would have
`refreshedAt > fallbackWatermark = 0` and look FRESH. Any invalidation
event that happened in the brief window between "clear started" and
"fresh entries written" would be silently lost. Keeping the watermark
across clears ensures that path is closed.

### Why callers can't accidentally bypass it

`bumpFallbackWatermark($ts)` defends against zero/negative timestamps
by coercing them to `time()`. An epoch watermark
(`fallbackWatermark = 0`) would make `refreshedAt > 0 && refreshedAt < 0`
evaluate to false for every entry forever — silently freezing the
entire cache as FRESH. The coercion is the guarantee that a
fat-fingered caller can't disarm the safety floor.

## Walkthrough: a request hits a HIT

```
1. Request arrives at /pimcore-datahub-webservices/...
2. preHandle:
   - computes (client, canonical-body) → meta key + payload key
   - loads both from Redis
   - compares meta.refreshedAt against the watermark → FRESH
3. Response built from payload, returned to client.
4. As a side-effect of HIT:
   - meta.refreshedAt is bumped to now and re-saved
   - if past payload TTL half-life, payload is repainted (saved
     again with a fresh TTL) to keep hot queries from expiring
```

No locks, no refresh, no queue work. The hot path is two Redis reads.

## Walkthrough: a request hits a STALE

```
1. preHandle:
   - loads meta + payload from Redis
   - meta.refreshedAt < watermark → STALE
2. Response built from payload, served immediately with
   `X-Pimcore-DataHub-Persistent-Cache: STALE` header. Client
   experiences no extra latency.
3. Request marked with `_datahub_persistent_refresh = true`.
4. After response is flushed to the client (kernel.terminate),
   the refresh-on-terminate listener fires:
   - checks the sentinel for this query
   - if sentinel present → another refresh already queued/running, skip
   - if absent → writes sentinel + dispatches a refresh message
     onto the Messenger bus
5. Worker eventually picks the message off the queue, acquires the
   refresh lock, re-runs the resolver, writes fresh payload + meta,
   clears the sentinel.
```

## Walkthrough: a request hits a MISS (cold start)

```
1. preHandle: meta or payload missing → return null → controller
   proceeds to execute the resolver inline.
2. Cold-miss herd protection (per query): if multiple identical
   requests arrive simultaneously and all see MISS, they race for a
   cold-miss lock. Winner runs the resolver; losers wait up to
   `swr_cold_miss_lock_wait_ms` (5s) for the winner to publish, then
   fall back to running their own inline resolver if the winner is
   still not done.
3. On successful response, postHandle saves payload + meta + updates
   the reverse index for every tag the request touched.
4. Next request for the same (client, query) → HIT.
```

## Walkthrough: an invalidation event fires

Pimcore fires `POST_UPDATE` / `POST_DELETE` on a DataObject (and
analogous events for Documents / Assets).

```
1. The invalidation listener receives the event.
2. Filters out save-version-only events (autosave / draft) — these
   don't advance the published version pointer, so the cache stays
   correct without any refresh.
3. Extracts the affected element. Computes its tags:
     obj_<Class>_<id>        per-object tag
     class_<Class>           per-class tag
4. For each tag, walks the reverse index:
     taginx_<tag> → list of (payload-key, meta-key) pairs
5. For each pair:
     - loads the meta to recover (client, canonical-body)
     - hashes them → sentinel key + pending key
     - if sentinel present:
         → write pending flag, no dispatch
     - if sentinel absent:
         → write sentinel, dispatch refresh message to queue
6. If the reverse index found nothing depending on this element's
   tags, bump the watermark (safety floor — turns every cached entry
   STALE).
```

## Walkthrough: an in-flight refresh finishes

```
1. Worker's controller invocation returns success.
2. In the `finally` block:
   - lock released
   - LockSignalRefresher disarmed (stops SIGALRM lock-renewal ticks)
   - reconcileCoalesceFlags runs:
       - reads pending flag
       - removes pending flag (if set)
       - removes sentinel
       - if pending was set → dispatches one trailing refresh
3. Worker picks the next message off the queue.
```

On **failure** (exception, non-2xx response, GraphQL errors-only
payload), the lock is still released but `reconcileCoalesceFlags` is
**skipped**. The sentinel is left in place so Messenger's retry path
doesn't accumulate duplicate dispatches; it eventually expires on its
TTL, costing a brief coalesce window.

## The locks

Four distinct Symfony Lock resources guard four independent contention
surfaces. They have separate key spaces and don't interact directly.

| Lock | Key shape | Set where | Purpose | TTL |
|---|---|---|---|---|
| **Refresh lock** | `datahub_persistent_refresh_lock_<md5>` (per-query) for SWR_ONLY, per-op-name for HERD_GUARDED | Worker handler on message receipt; also the inline kernel.terminate refresh path | Serialize concurrent refreshes of the same query. Mandatory for correctness. | 120s, SIGALRM-renewed every 60s |
| **Cold-miss lock** | `datahub_swr_cold_miss_<md5>` (per-query) | Cold-miss path when MISS | Avoid herd-of-resolvers on cold start of a hot query | 30s, SIGALRM-renewed |
| **Herd-guard lock** | `datahub_inprogress:*` (per-op-name) | OutputCacheService herd-guard layer | Reject (503 + Retry-After) duplicate parallel requests for HERD_GUARDED ops while one is in progress | configurable, SIGALRM-renewed |
| **Refresh marker** (legacy fallback) | similar shape to refresh lock | Inline kernel.terminate path when no Symfony LockFactory is wired | Best-effort dedupe via cache key when proper locking unavailable | tied to refresh lock TTL |

The first three are **renewed via SIGALRM** every TTL/2 seconds for as
long as the holder is running. Renewal is mandatory for the refresh
lock specifically because losing it mid-refresh would let another
worker start a parallel refresh and clobber the payload write.

The **dedupe sentinel** (`datahub_enqueue_req_<hash>`) is *not* a
Symfony Lock — it's a plain cache key with a TTL, and is *not*
renewed. See *Tuning notes* below.

## Two operation tiers

Operations declared in the bundle config carry a `tier`:

| Tier | Refresh lock granularity | Behavior on a duplicate parallel request |
|---|---|---|
| `herd_guarded` | per **operation name** — all refreshes of the same operation serialize globally | Standard output cache layer rejects with 503 + Retry-After while one is in progress |
| `swr_only` | per **(client, canonical body)** — each variable combination is independently locked | No 503 — concurrent variations of the same operation can refresh in parallel (subject to worker concurrency) |

`herd_guarded` is for low-cardinality high-traffic operations
(front-page-shaped queries). `swr_only` is for high-cardinality
per-variable operations (per-product lookups, listing filters).

Granularity (`single` vs `list`) is a separate axis that controls
which collector tags the cache write records — `single` records
per-object tags so per-object invalidations can target the entry
precisely; `list` records per-class tags so any class-level change
invalidates the listing.

## The queue and the worker

Refresh messages are dispatched onto a Redis-backed ZSET priority
queue (`datahub_refresh_priority_queue`), not the default Symfony
Messenger Doctrine transport. The ZSET score determines pop order:

- `oldest_refreshed_at_first` (default): score = the entry's
  `refreshedAt` timestamp, so the longest-stale messages pop first.
- `oldest_refreshed_at_first_with_weight_bands`: same baseline minus
  `priority_weight × band_seconds`, so higher-weight operations drop
  into an earlier band.

A single worker pod runs `messenger:consume` against this transport
(`replicas: 1` in the worker chart). Worker parallelism is therefore
**one refresh at a time across the whole queue**. The refresh lock
above is defense-in-depth for the case where someone scales the
worker > 1 or where the inline kernel.terminate path runs alongside
the worker.

## Failure modes and recovery

| What goes wrong | What happens | Recovery |
|---|---|---|
| Worker dies mid-refresh (OOM, eviction, SIGKILL) | Refresh lock released by Redis TTL (or by Symfony Lock's destructor on graceful shutdown). Sentinel expires on its own TTL. | Next invalidation re-dispatches fresh. Up to `enqueue_dedupe_ttl` seconds of "STALE but served from cache" for affected queries. |
| Resolver throws / returns non-2xx / errors-only payload | `savePersistent` refuses to persist (would otherwise turn a transient outage into a sticky cached error). Sentinel left to TTL-expire so Messenger retries don't pile up dispatches. | Messenger retries the message (up to 3 retries with exponential backoff). If all retries fail, the message lands in the failure queue and the entry stays STALE until the next invalidation triggers a fresh dispatch. |
| Redis is down | Cache loads return false → MISS path. Cold-miss lock factory unavailable → loud warning + falls through to inline resolver. Cache writes throw → caught and logged. | When Redis returns, the system self-heals — first request after recovery is a MISS, gets cached, normal operation resumes. |
| Sentinel TTL expires mid-refresh (because refresh is unusually long, or queue is unusually deep) | New invalidations after the expiry trigger fresh dispatches → duplicate refresh messages queue behind the in-flight one → wasted work, no correctness loss | Tune `persistent_enqueue_dedupe_ttl` to comfortably exceed worst-case (queue wait + refresh duration). See *Tuning notes* below. |
| Reverse index entry malformed | Listener logs per-entry warning, skips the entry; never amplifies a data-shape bug into a global watermark bump. | Surface the warnings in logs; bad entries naturally cycle out as their payloads age out. |
| Bundle-level fatal (entire SWR layer broken) | `clearAll()` drops every payload + meta + reverse-index entry, **preserving** the watermark so freshly-written entries don't all look FRESH until the next external invalidation. | Re-enable SWR, traffic warms the cache organically. |

## Observability

The layer narrates every decision through `\Pimcore\Logger` (the Pimcore
application logger) under four greppable message prefixes — one per
moving part:

| Prefix | Emitted by | What it tells you |
|---|---|---|
| `persistent_cache_invalidation` | the invalidation listener | which entries an edit invalidated; per-query dispatch vs. coalesce vs. watermark-bump; cooldown arm / "dated refresh already queued" decisions |
| `datahub.refresh_dispatch` | the read path (`kernel.terminate`) | a stale read enqueued a refresh (operation / client / variables / request URI) |
| `datahub.refresh_handler` | the refresh worker | a queued refresh started / completed (duration, peak memory), lock-contention requeues, trailing-refresh and cooldown-window-close dispatches |
| `datahub.swr` | `PersistentOutputCacheService` | cache-save / HIT-repaint outcomes and malformed-meta guards on the read path |

These lines land wherever the application logger's Monolog handler is
configured to write — commonly `var/log/<kernel-env>.log`, plus a
`var/log/<kernel-env>-debug.log` variant when the deployment enables a
debug-level file handler. To watch all four live:

```
tail -f var/log/<kernel-env>-debug.log \
  | grep -iE "datahub\.(refresh_handler|refresh_dispatch|swr)|persistent_cache_invalidation"
```

Use the **debug** log: several lines — notably the `refresh_handler`
message-drop reasons (unclassified op, missing operation name) and the
malformed-reverse-index guards — are emitted at `debug` level and won't
appear in an error-only log. Note that `refresh_handler` lines originate
in the worker process, so they only share a log file with the dispatch /
invalidation side if the worker and FPM write to the same path.

## Tuning notes

### Where actual values live

The bundle ships **conservative defaults** suitable for any deployment
shape; the actual tuned values for this workspace live in the
installation config under `pimcore_data_hub.graphql:` —
`pimcore-installation/pimcore/config/config.yaml`. Read that file
alongside this section: the comments next to each value capture the
reasoning behind the override.

The values below describe **what each knob is for** and **how to size
it**; the current numeric values are in the installation config.

### Sentinel TTL sizing (`persistent_enqueue_dedupe_ttl`)

**Bundle default: 60s. Conservative — assumes immediate worker
pickup, which is right for empty-queue scenarios.**

The sentinel must outlive the **worst-case time from "invalidation
detected" to "worker finishes refreshing that specific query"**, not
just the refresh duration itself. With a single-replica worker, queue
wait time can dominate:

```
e.g. editor batch-publishes → 25 distinct queries invalidated →
     25 messages queued, each refresh ~3 min → worst-case message at
     position 25 waits 75 min before being picked up.

     If sentinel TTL < 75 min + 3 min, an invalidation arriving more
     than TTL seconds after the original dispatch will trigger a
     duplicate dispatch instead of being coalesced via the pending flag.
```

Suggested ranges:

| Workload shape | Suggested TTL |
|---|---|
| Light editing, queue typically empty | 300s |
| Bursty publishing with multi-minute refreshes | 1800s (30 min) |
| Worst-case content pushes affecting most cached queries | 3600s (60 min) |

Cost of higher TTL: if a worker crashes mid-refresh, affected queries
stay STALE for up to TTL seconds before the next invalidation triggers
a re-dispatch. Generally tolerable — STALE means *served from cache*,
not *broken*.

### Pending flag TTL

Auto-derives from sentinel TTL as `max(sentinel_ttl × 10, 600)`. Not
separately configurable. The flag only needs to outlive the sentinel
by enough to bridge "last pending-flag bump" → "worker reads it on
completion", so the 10× multiplier is comfortable but not tight.
Bumping the sentinel auto-scales the pending flag.

### Lock TTLs (`persistent_refresh_lock_ttl`, `persistent_refresh_operation_lock_ttl`)

**Bundle default: 120s for both. Tight — sized for fast refreshes.**

These are renewed by SIGALRM every TTL/2 seconds. As long as the
worker process is alive and the pcntl extension is loaded, the lock
survives arbitrarily long refreshes. The TTL bounds the leak window
only on hard kills (SIGKILL, OOM).

A larger value adds nothing functionally (renewal does the work) but
buys margin on platforms where SIGALRM isn't available — pcntl-less
PHP builds silently disable the refresher, in which case the lock
expires on its TTL alone. The installation config sets 600s for both
to comfortably outlive worst-case refreshes even without renewal.

### Payload TTL (`persistent_output_cache_payload_ttl`)

**Bundle default: 86400 (1d).**

The freshness clock on the meta key rolls forward every read; the
payload clock doesn't. A hot query's payload could expire from under
its alive meta — `preHandle` defends against this with **half-life
repaint**: on every FRESH HIT, if the payload has crossed half its
TTL, it's saved again with a fresh TTL clock. The 1d default leaves
plenty of room for daily-edited content; the per-granularity override
(`persistent_output_cache_payload_ttl_by_granularity`) bumps `list`
queries to 14d since they're refreshed proactively on every
invalidation anyway.

## Where to look next

- **`Persistent-Cache-Architecture.md`** — same concepts mapped to
  specific classes, methods, configuration knobs, and file paths.
- **Wiki: DataHub-SWR-Cache** — the workspace's higher-level view of
  the two-tier SWR cache, including the upstream `pimcore-cache` Rust
  proxy that fronts this bundle.
- **`src/DependencyInjection/Configuration.php`** — the canonical
  source of all configuration knobs and their defaults.
