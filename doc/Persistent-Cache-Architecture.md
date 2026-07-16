# Persistent Cache Architecture

Code-level companion to `Persistent-Cache-Flow.md`. Same concepts,
mapped to specific classes, methods, and configuration knobs. Read
the flow doc first if you haven't — this doc assumes the conceptual
model is in place and focuses on *where things live* and *how the
pieces wire together*.

## Code map

```
src/
├── Controller/
│   └── WebserviceController.php           ← GraphQL entry point; calls
│                                            preHandle / postHandle, owns
│                                            the cold-miss + herd-guard
│                                            orchestration
├── EventListener/
│   ├── PersistentCacheInvalidationListener.php
│   │                                       ← reacts to POST_UPDATE /
│   │                                         POST_DELETE; the active
│   │                                         (invalidation-triggered)
│   │                                         refresh dispatcher
│   ├── PersistentCacheRefreshOnTerminateListener.php
│   │                                       ← reacts to kernel.terminate
│   │                                         when preHandle marked the
│   │                                         request stale; the passive
│   │                                         (read-triggered) refresh
│   │                                         dispatcher
│   └── InProgressLockReleaseListener.php   ← releases the herd-guard lock
│                                            on kernel.terminate
├── MessageHandler/
│   └── PersistentRefreshMessageHandler.php ← Messenger worker; consumes
│                                            PersistentRefreshMessage,
│                                            acquires refresh lock,
│                                            re-runs the resolver,
│                                            reconciles coalesce flags
├── Messenger/
│   ├── PriorityRedisTransport.php          ← Redis-ZSET priority
│   │                                         queue transport
│   └── PriorityRedisTransportFactory.php
├── Service/
│   ├── OutputCacheService.php              ← standard (short-TTL) output
│   │                                         cache + herd-guard layer
│   ├── PersistentOutputCacheService.php    ← the SWR layer; preHandle,
│   │                                         postHandle, savePersistent,
│   │                                         the watermark, the indices,
│   │                                         the cold-miss lock
│   ├── OperationClassifier.php             ← per-op tier / granularity
│   │                                         lookup; built from config
│   ├── Tier.php                            ← enum: HERD_GUARDED,
│   │                                         SWR_ONLY, NEITHER
│   ├── Granularity.php                     ← enum: SINGLE, LIST
│   ├── DependencyCollector.php             ← collects per-object /
│   │                                         per-class tags during a
│   │                                         GraphQL request (driven
│   │                                         by POST_LOAD subscriber)
│   ├── GraphQLRequestCanonicalizer.php     ← canonicalizes the request
│   │                                         body so equivalent requests
│   │                                         hash to the same keys
│   ├── FrontendRequestScope.php            ← runs an out-of-request
│   │                                         GraphQL execution (worker,
│   │                                         console, kernel.terminate)
│   │                                         inside a frontend request so
│   │                                         asset paths URL-encode
│   │                                         identically to FPM writes
│   ├── CooldownRefreshPolicy.php           ← pure decision engine for the
│   │                                         per-operation cooldown throttle
│   │                                         (forward + reverse arms)
│   ├── CooldownWindowDispatcher.php        ← owns the cooldown I/O (arm /
│   │                                         clear sentinel, dispatch dated
│   │                                         message) around the policy
│   ├── CooldownInvalidationDecision.php    ← forward-arm value object
│   │                                         (leading-edge / coalesce /
│   │                                         open-trailing)
│   └── CooldownTrailingDecision.php        ← reverse-arm value object
│                                            (cancel / fire / re-arm)
├── Lock/
│   ├── LockSignalRefresher.php             ← SIGALRM-based renewer for
│   │                                         Symfony Locks
│   └── LockFactoryResolver.php             ← obtains a LockFactory
└── DependencyInjection/
    └── Configuration.php                   ← bundle config schema
```

## Request lifecycle: the read path

GraphQL request lands at `WebserviceController::webonyxAction`
(routed via `pimcore-data-hub/src/Resources/config/services.yml`
and `pimcore-data-hub/src/Resources/config/pimcore/routing.yaml`).

```
WebserviceController::webonyxAction
    │
    ├── (1) standard OutputCacheService::preHandle
    │       ├── computes cache key from request
    │       ├── may return cached JsonResponse → short-circuit
    │       └── runs herd-guard check for HERD_GUARDED tier:
    │             - acquires herd-guard lock (per-op-name or per-request)
    │             - on miss-lock returns 503 + Retry-After
    │
    ├── (2) PersistentOutputCacheService::preHandle  ← THE SWR LAYER
    │       │
    │       │  if !shouldUseForRequest(request): return null
    │       │     - persistent cache disabled, OR
    │       │     - not a POST, OR
    │       │     - no operationName, OR
    │       │     - operationName not in classifier
    │       │
    │       │  if request marked _datahub_persistent_refresh: return null
    │       │     (we're inside a background-refresh sub-request — let
    │       │      the resolver run; postHandle will save the result)
    │       │
    │       │  load meta + payload by sidecar keys (sha256 hash)
    │       │
    │       │  if either missing: return null   ← MISS
    │       │
    │       │  if refreshedAt < fallbackWatermark:
    │       │     - mark request _datahub_persistent_refresh = true
    │       │     - set meta-key / payload-key / refreshedAt attributes
    │       │     - return STALE JsonResponse with X-...-Cache: STALE
    │       │       (kernel.terminate will dispatch the refresh)
    │       │
    │       │  else (FRESH HIT):
    │       │     - bump meta.refreshedAt → now
    │       │     - if past payload-TTL half-life: repaint payload
    │       │       (re-save with fresh TTL to bound the "hot query
    │       │        loses its payload while meta is alive" gap)
    │       │     - return FRESH JsonResponse with X-...-Cache: HIT
    │       │
    ├── (3) resolver runs — only reached on MISS or refresh sub-request
    │       (this is the expensive path; everything above exists to
    │        avoid reaching it)
    │
    ├── (4) PersistentOutputCacheService::postHandle
    │       │
    │       │  side-effect only; never mutates the response
    │       │  guards:
    │       │     - HTTP 2xx only (refuses to cache transient errors)
    │       │     - non-empty array payload only
    │       │     - errors-only payloads (errors set with no useful
    │       │       data) — including a `data` array whose members are
    │       │       all null — refused
    │       │  calls savePersistent → writes payload + meta + indices
    │       │  also updates the reverse index for every collected tag
    │       │
    └── (5) standard OutputCacheService::postHandle
            (may be a no-op when persistent_disable_output_cache_for_guarded
             is true and the persistent layer applied — config knob keeps
             the standard layer from double-storing the same response)
```

Cold-miss herd protection sits between (1) and (2): when the
persistent layer returns null (MISS) for an `swr_only` operation,
`WebserviceController` calls `PersistentOutputCacheService::acquireColdMissLock`
to serialize the herd of concurrent identical MISS requests around
the resolver run. Winner runs the resolver; losers wait up to
`swr_cold_miss_lock_wait_ms` for the winner to publish, then fall
back to running their own resolver inline if the wait expires.

## Save path: `savePersistent`

`PersistentOutputCacheService::savePersistent` is the single write
point. Called from `postHandle` after a successful resolver run.

```php
// Computed inputs
[$client, $canonical] = $this->clientAndCanonical($request);
$metaKey    = self::keyMetaFor($client, $canonical);
$payloadKey = self::keyPayloadFor($client, $canonical);

// Tags assembled from three sources
$baseTags      = $this->buildTags(...);              // TAG_COMMON, TAG_CLIENT_*, TAG_OP_*
$collectorTags = $this->collectorTagsForOperation(...); // TAG_OBJECT_*, TAG_CLASS_*
$tags          = array_unique([...$baseTags, ...$collectorTags]);

// Per-op TTL override if classifier knows the op
$payloadTtl = $classifier->getTtl($operationName) ?? $this->payloadTtl;

// Two writes — payload (long TTL) + meta (shorter TTL)
$this->cacheSave($payloadKey, $payload, $tags, $payloadTtl);
$this->cacheSave($metaKey,    $meta,    $tags, $this->ttl);

// Forward indices (membership tracking by all/op/client)
$this->updateIndices($payloadKey, $client, $operationName);

// Reverse indices (tag → list of (payloadKey, metaKey) pairs)
$this->updateReverseIndices($collectorTags, $payloadKey, $metaKey);
```

The collector tags drive **per-object invalidation precision**. The
`DependencyCollector` records `<class>:<id>` pairs as the resolver
loads DataObjects. For `single`-granularity ops, the collector
provides per-object tags so a single object update can target this
exact cache entry. For `list`-granularity ops, only per-class tags
are recorded, so any object of that class invalidates the listing.

`shouldUseForRequest` is the gate: it consults the OperationClassifier
(`hasOperation`) — only operations explicitly declared under
`graphql.operations` in config participate in the persistent cache.
Unknown operations bypass entirely and run the resolver every time.

## The watermark

### Storage

```
KEY:  datahub_graphql_fallback_watermark_ts
VAL:  unix timestamp (int)
TAG:  TAG_WATERMARK ("datahub_graphql_persistent_watermark")
TTL:  null = cache-pool default (7d), renewed on each bump
```

The watermark lives under its own dedicated tag —
`TAG_WATERMARK` — separate from `TAG_COMMON`. This isolation is
load-bearing for `clearAll()`: clearing every entry tagged
`TAG_COMMON` would *include* the watermark and reset it to absent,
making every fresh post-clear write look FRESH against a missing
watermark. The dedicated tag keeps the watermark out of bundle-level
clears.

### Freshness predicate (in `preHandle`, `probeStatus`)

```php
$fallbackWatermark = (int) ($this->cacheLoad(self::KEY_FALLBACK_WATERMARK_TS) ?: 0);
$refreshedAt      = (int) ($meta['refreshedAt'] ?? 0);
$isStale = $fallbackWatermark > 0
        && $refreshedAt      > 0
        && $refreshedAt      < $fallbackWatermark;
```

Both timestamps must be > 0 for the comparison to produce STALE.
This is a defensive guard against an epoch watermark
(`fallbackWatermark == 0`) silently freezing the entire cache as
FRESH — which `bumpFallbackWatermark` actively prevents by coercing
zero/negative arguments to `time()`.

### Where the watermark is bumped

`PersistentOutputCacheService::bumpFallbackWatermark($ts = null)`
is the only writer. It is called from
`PersistentCacheInvalidationListener::mark` in four explicit
fall-through cases:

| Trigger | Location | Reason |
|---|---|---|
| Queue path disabled | branch at start of `mark()` | The dispatch mechanism isn't wired; watermark is the only way to signal staleness |
| Non-element event | `extractElement` returns null | No tags to walk → no targeted dispatch possible |
| Reverse-index lookup found nothing for all affected tags | inside `dispatchForTags`, `hadReverseIndexHits == false` | No cached entries depend on this object today — but watermark protects against future writes that would |
| Exception during dispatch | outer try/catch in `mark()` | Defensive — never let a listener exception silently drop an invalidation |

Note what's **not** in this list: a normal dispatch (one or more
messages queued, or one or more entries coalesced into pending
flags) does **not** bump the watermark. The per-query dispatch is
the precise path; bumping the watermark on top of it would
cascade-stale every unrelated entry too.

### Cost model

| Path | Effect |
|---|---|
| Per-query dispatch (primary) | Schedules N refreshes (N = entries that depend on the changed object) — bounded by reverse-index size, typically 1–20 |
| Watermark bump (fallback) | Every subsequent read of every cached entry returns STALE and triggers a read-path refresh dispatch via `PersistentCacheRefreshOnTerminateListener` — unbounded in worst case, but sentinel-coalesced so each distinct query produces at most one refresh per sentinel TTL |

## Invalidation event flow

`PersistentCacheInvalidationListener::getSubscribedEvents`:

```
DataObjectEvents::POST_UPDATE
DataObjectEvents::POST_DELETE
DocumentEvents::POST_UPDATE
DocumentEvents::POST_DELETE
AssetEvents::POST_UPDATE
AssetEvents::POST_DELETE
```

All routed to `mark()`. Inside:

```
mark(Event $event):
    if isVersionOnlySave($event):           # autosave / draft — no refresh needed
        return

    if !queueEnabled:                        # config-disabled path
        bumpFallbackWatermark()              # watermark fallback
        return

    $element = extractElement($event)
    if $element === null:                    # non-element event
        bumpFallbackWatermark()              # watermark fallback
        return

    $tags   = tagsForElement($element)       # [obj_<class>_<id>, class_<class>]
    $result = dispatchForTags($tags, $enqueueTtl)

    if $result['dispatched'] !== []:
        log dispatched
        return                                # primary path succeeded

    if $result['coalesced'] > 0:
        log coalesced (sentinel-present case — pending flag was set)
        return                                # primary path succeeded too

    if !$result['hadReverseIndexHits']:
        bumpFallbackWatermark()              # watermark fallback —
                                              # nothing in cache depends
                                              # on these tags today
    else:
        log "all reverse-index entries malformed"
        # don't watermark — would amplify a data-shape bug
```

`dispatchForTags` walks the reverse index for each tag, deduplicates
by payload key (so two tags pointing at the same entry don't
double-dispatch), and for each surviving entry:

```
$hash       = sha256("client:<client>\n<canonical>")
$dedupeKey  = ENQUEUE_DEDUPE_PREFIX  . $hash   # datahub_enqueue_req_<hash>
$pendingKey = PENDING_REFRESH_PREFIX . $hash   # datahub_pending_refresh_<hash>

if cacheLoad($dedupeKey):
    cacheSave(1, $pendingKey, TAG_COMMON, max($enqueueTtl * 10, 600))
    coalesced++
    continue

cacheSave(1, $dedupeKey, TAG_COMMON, $enqueueTtl)
bus->dispatch(new PersistentRefreshMessage($client, $canonical, $operation, time()))
dispatched[] = ...
```

The score for the priority queue is `time()` because the listener has
no per-entry `refreshedAt` context — every entry is freshly stale at
this point, so they're roughly equivalent and the transport will
ZPOPMIN by insertion order within the same-second bucket.

## Read-triggered refresh: kernel.terminate

`PersistentCacheRefreshOnTerminateListener::onKernelTerminate` fires
on every request that `preHandle` flagged with
`_datahub_persistent_refresh = true` (i.e. every STALE-HIT request).

```
if !request->attributes->get('_datahub_persistent_refresh'):
    return

if persistent_refresh_queue_enabled:
    dispatchToBus()                          # queue path
else:
    runInline()                              # legacy fallback
```

The queue path is the only one in production use. It builds the message
from the inputs already captured on the request and dispatches
**unconditionally** — it neither writes nor checks the
`datahub_enqueue_req_` sentinel:

```
dispatchToBus(request, $graphql):
    $payload       = request->getContent()
    $client        = request attr 'clientname'
    $op            = operationName from payload
    $scoreBaseline = request attr '_datahub_persistent_refreshed_at' ?: time()
    bus->dispatch(new PersistentRefreshMessage(
        $client, $payload, $op, $scoreBaseline, $priorityWeight,
        readTriggered: true))
```

The read path no longer participates in sentinel-based dispatch dedupe.
Transient read-side duplicates for the same query are absorbed
downstream: the worker's execution-time freshness guard
(`shouldRunRefresh` — skip the resolver if the entry is already fresh)
plus the per-entry refresh lock make a redundant read dispatch cheap. The
invalidation-triggered dispatcher still owns the sentinel; both converge
on the same worker.

## Worker: `PersistentRefreshMessageHandler`

```
__invoke(PersistentRefreshMessage $message):
    $operationName = $message->operationName ?? '?'
    $tier          = $classifier->getTier($operationName)
    if $tier === Tier::NEITHER:
        log "unclassified op; dropping message"
        return

    $factory = $lockResolver->resolve()
    if $factory === null:
        log "lock factory unavailable; dropping message"
        return

    $ttl = $graphql['persistent_refresh_lock_ttl']    # 120s default, 600s configured

    if $tier === Tier::HERD_GUARDED:
        $lockResource = OutputCacheService::computeOperationLockKey($operationName)
    else:                                              # Tier::SWR_ONLY
        $lockResource = PersistentOutputCacheService::computeSwrRefreshLockKey($client, $bodyJson)

    $lock = $factory->createLock($lockResource, $ttl, autoRelease: false)
    if !$lock->acquire(blocking: false):
        throw new RecoverableMessageHandlingException('lock contended; requeue')

    LockSignalRefresher::arm($lock, $ttl, $ttl / 2)    # SIGALRM every TTL/2

    $controllerSucceeded = false
    try {
        $request = Request::create('/datahub/graphql', 'POST', body: $bodyJson)
        $request->attributes->set('clientname', $message->client)
        $request->attributes->set('_datahub_persistent_refresh', true)        # preHandle skips
        $request->attributes->set('_datahub_bypass_in_progress_guard', true)  # herd-guard skips

        try {
            $controller->webonyxAction(...)
            $controllerSucceeded = true
        } catch (\Throwable $e) {
            if ($e instanceof RecoverableMessageHandlingException) throw  # propagate retry
            log "controller invocation failed; non-fatal"                  # swallow other errors
        }
    } finally {
        LockSignalRefresher::disarm()
        $lock->release()

        if ($controllerSucceeded) {
            $this->reconcileCoalesceFlags($message, $operationName)
        }
        # On failure: leave sentinel to TTL-expire so retries don't pile
        # up duplicate dispatches.

        log "completed"
    }
```

`reconcileCoalesceFlags`:

```
$hash       = sha256("client:<client>\n<bodyJson>")
$dedupeKey  = ENQUEUE_DEDUPE_PREFIX  . $hash
$pendingKey = PENDING_REFRESH_PREFIX . $hash

$pending = cacheLoad($pendingKey)
if $pending:
    cacheRemove($pendingKey)
cacheRemove($dedupeKey)

if $pending && $bus !== null:
    $bus->dispatch(new PersistentRefreshMessage(
        $message->client,
        $message->bodyJson,
        $message->operationName,
        time(),
        $message->priorityWeight
    ))
    log "trailing refresh dispatched"
```

One trailing refresh per finished message, no matter how many
pending bumps happened during its run.

The `webonyxAction` invocation runs inside `FrontendRequestScope::run`,
which pushes the synthetic refresh request onto the `RequestStack` as a
frontend main request. Without it, an out-of-request execution (worker,
console, `kernel.terminate`) has an empty stack, so `Asset::getFullPath()`
skips its `Tool::isFrontend()` URL-encoding branch and worker-written
payloads would carry unencoded asset paths that differ from FPM-written
ones — the same `(client, query)` entry would hash-collide on identical
input yet serve byte-divergent bodies depending on which process last
wrote it. The scope makes cache content independent of the writer.

## Lock spaces

Four distinct Symfony Lock resources, each guarding a different
contention surface. They share no key space and don't compose.

```
┌──────────────────────────────────────────────────────────────────────┐
│ 1. Herd-guard lock — guards parallel requests for HERD_GUARDED ops  │
│    Resource: datahub_inprogress:<op-name>  (or per-request hash)     │
│    Set by:   OutputCacheService herd-guard preHandle                 │
│    TTL:      herd_guard_ttl (configurable); SIGALRM-renewed          │
│    On miss:  503 + Retry-After                                        │
├──────────────────────────────────────────────────────────────────────┤
│ 2. Cold-miss lock — guards parallel MISS requests for SWR_ONLY ops  │
│    Resource: datahub_swr_cold_miss_<md5(metaKey|payloadKey)>         │
│    Set by:   PersistentOutputCacheService::acquireColdMissLock       │
│    TTL:      swr_cold_miss_lock_ttl (30s); SIGALRM-renewed           │
│    On miss:  loser waits swr_cold_miss_lock_wait_ms (5s) then falls   │
│              through to inline resolver — never 503                   │
├──────────────────────────────────────────────────────────────────────┤
│ 3. Refresh lock — guards parallel refreshes of the same query        │
│    Resource (HERD_GUARDED): per op-name                              │
│    Resource (SWR_ONLY):     datahub_persistent_refresh_lock_<md5>     │
│    Set by:   PersistentRefreshMessageHandler                         │
│              PersistentCacheRefreshOnTerminateListener (legacy path) │
│    TTL:      persistent_refresh_lock_ttl (120s default, 600s here);  │
│              SIGALRM-renewed                                          │
│    On miss:  RecoverableMessageHandlingException → Messenger retries │
├──────────────────────────────────────────────────────────────────────┤
│ 4. Refresh marker — last-resort dedupe when no LockFactory wired    │
│    Resource: similar shape to refresh lock                            │
│    Set by:   PersistentCacheRefreshOnTerminateListener (inline path) │
│              only when LockFactory is null                            │
│    TTL:      persistent_refresh_lock_ttl                              │
│    Released: try/finally; not a proper lock — exists only as a       │
│              cache-key dedupe fallback                                │
└──────────────────────────────────────────────────────────────────────┘
```

The dedupe sentinel (`ENQUEUE_DEDUPE_PREFIX`) is intentionally **not
a Symfony Lock** — it's a plain cache key with a TTL. Its job is
dispatch-side coalescing, not mutual exclusion, so all the lock
machinery (acquire/release, factory, SIGALRM) would be overkill.
The trade-off: it can't be renewed, so the TTL must be sized for
the full coalesce window.

## Redis key namespace

All prefixes defined as `PersistentOutputCacheService` constants:

| Constant | Prefix | Purpose |
|---|---|---|
| `PAYLOAD_KEY_PREFIX` | `persistent_output_payload_` | Cached JSON response body |
| `META_KEY_PREFIX` | `persistent_output_meta_` | Per-entry metadata (refreshedAt, tags, ...) |
| `ENQUEUE_DEDUPE_PREFIX` | `datahub_enqueue_req_` | Dispatch-dedupe sentinel |
| `PENDING_REFRESH_PREFIX` | `datahub_pending_refresh_` | Coalesce flag (worker reads → trailing dispatch) |
| `KEY_OP_COOLDOWN_PREFIX` | `datahub_graphql_op_cooldown_` | Invalidation-cooldown sentinel (per entry, tagged `TAG_WATERMARK`) |
| `KEY_FALLBACK_WATERMARK_TS` | `datahub_graphql_fallback_watermark_ts` | Global watermark |
| `REVERSE_INDEX_PREFIX` | `taginx_` | Tag → list of (payloadKey, metaKey) pairs |
| `INDEX_ALL` | `datahub_graphql_persistent_index_all` | Membership: all keys |
| `INDEX_OP_PREFIX` | `datahub_graphql_persistent_index_op_` | Membership by operation name |
| `INDEX_CLIENT_PREFIX` | `datahub_graphql_persistent_index_client_` | Membership by client |

Tag namespace (`TAG_*` constants):

| Constant | Value | Purpose |
|---|---|---|
| `TAG_COMMON` | `datahub_graphql_persistent` | Carries every payload + meta + index; cleared by `clearAll()` |
| `TAG_WATERMARK` | `datahub_graphql_persistent_watermark` | Carries only the watermark; isolated from `TAG_COMMON` |
| `TAG_OP_PREFIX` | `datahub_graphql_op_` | Per-operation tag for op-level invalidation |
| `TAG_CLIENT_PREFIX` | `datahub_graphql_client_` | Per-client tag for client-level invalidation |
| `TAG_OBJECT_PREFIX` | `datahub_graphql_obj_` | Per-object tag (DataObject `<class>_<id>`) |
| `TAG_CLASS_PREFIX` | `datahub_graphql_class_` | Per-class tag |

`_` (underscores) in tags/keys instead of `:` — PSR-6 reserves
`{}()/\@:` and CacheItem validation throws on any of them.

## Hash computation

The four per-query keys (`payload`, `meta`, `sentinel`, `pending`)
all derive from the same input:

```php
$canonical = GraphQLRequestCanonicalizer::canonicalize($request->getContent());
$hash      = hash('sha256', 'client:' . $client . "\n" . $canonical);

$payloadKey  = PAYLOAD_KEY_PREFIX  . $hash;
$metaKey     = META_KEY_PREFIX     . $hash;
$dedupeKey   = ENQUEUE_DEDUPE_PREFIX  . $hash;
$pendingKey  = PENDING_REFRESH_PREFIX . $hash;
```

`canonicalize` strips/normalizes whitespace, operation order, and
variable order so equivalent requests hash identically. This is what
makes per-query dispatch precise: two different client requests for
the same GraphQL operation with the same variable values hit the
same cache entry and the same sentinel.

The refresh lock resource (SWR_ONLY tier) uses a slightly different
shape — md5 of the concatenated meta+payload key shape — so the
lock and the sentinel are in different Redis key namespaces:

```php
$lockResource = 'datahub_persistent_refresh_lock_'
              . md5($metaKey . '|' . $payloadKey);
```

## The reverse index

`taginx_<tag>` stores a list of `[$payloadKey, $metaKey]` pairs.
Built up incrementally by `updateReverseIndices` on every
`savePersistent`, walked by `dispatchForTags` on every invalidation.

Size discipline:

- Soft cap: `MAX_INDEX_SIZE = 5000` per tag.
- When the cap is crossed, the index is pruned: for each pair, load
  the payload key; drop the pair if the payload is gone.
- If still over after prune, FIFO-truncate the tail and emit a
  `persistent_cache: reverse-index truncated at max size` warning.

TTL / renewal: index entries are written with a `null` TTL, which is
**not** unlimited — Symfony's cache-pool `default_lifetime` caps it at
7 days (604800s). `addToReverseIndex` (and `addToIndex` for the forward
indices) therefore re-saves on **every** `savePersistent`, even when the
member already exists, purely to renew that 7-day clock. This matters: if
a stable-variant listing query stops being re-saved and its `taginx_`
entry lapses, the next element save of that class finds an empty reverse
index, falls through to the global-watermark-bump path, and cascade-stales
the whole cache (a fallback-watermark refresh storm). Renewal-on-touch is
what keeps a hot query's index alive indefinitely.

The forward indices (`INDEX_ALL`, `INDEX_OP_*`, `INDEX_CLIENT_*`)
follow the same pattern. None of these indices are tagged with
collector tags — they carry only `TAG_COMMON` so `clearAll()` drops
them cleanly without per-object dependency entanglement.

## Priority transport

`PriorityRedisTransport` (`src/Messenger/PriorityRedisTransport.php`)
is a custom Symfony Messenger transport backed by a Redis ZSET. The
default Doctrine transport polls the DB every second and generates
several hundred queries/sec of background chatter; Redis ZSET is
push/pop based.

Layout:

```
ZSET  datahub_refresh_priority_queue        score = priority, member = message id
HASH  datahub_refresh_priority_messages     id → serialized envelope
HASH  datahub_refresh_priority_inflight     id → marker (set by get, cleared by ack)
```

Send: MULTI / ZADD + HSET messages / EXEC. A **fresh** queue id is minted
on every send — even when the envelope arrives carrying a
`TransportMessageIdStamp` — because Messenger's retry flow re-sends the
received envelope and *then* rejects the original by its id; reusing the
inbound id would let that reject `HDEL` the body of the just-re-queued
retry copy and silently drop it. On a retry re-send (`RedeliveryStamp`
present) the score is bumped by
`persistent_refresh_priority_requeue_score_bump` to demote contended
messages so freshly-stale ones drain first. A Messenger `DelayStamp` is
honored as a ZSET visibility floor (`max(score, now + delay)`) so a
delayed re-queue stays invisible until due instead of tight-spinning the
worker — a genuinely-scheduled message (non-null `deliverAt`) is exempt
and owns its due-time score verbatim.

Get: a single Lua `EVAL` runs ZRANGEBYSCORE (lowest-scored member with
score `<= now`, LIMIT 1) + ZREM + HGET messages + HSET inflight in one
uninterruptible step. Folding the read and the claim into one script is
what makes the transport exactly-one-consumer-safe: no second consumer
can be handed the same id between the read and the remove. The
score-bounded read is also the scheduled-delivery gate (see below). The
reaper's stuck-inflight re-queue likewise runs a Lua `RECLAIM_SCRIPT` that
re-asserts the id is still inflight and still stale before ZADD + HDEL, so
two reapers can't resurrect the same id twice. The transport is therefore
safe at `N ≥ 2` consumers, and the worker deployment runs **2 replicas**.

Ack / reject: HDEL on both messages and inflight in MULTI/EXEC
(idempotent, so concurrent acks of distinct ids are safe).

Score strategies (`persistent_refresh_priority_strategy`):

| Strategy | Score = |
|---|---|
| `oldest_refreshed_at_first` (default) | `PersistentRefreshMessage::scoreBaseline` — longest-stale pops first |
| `oldest_refreshed_at_first_with_weight_bands` | same minus `priority_weight × band_seconds` — higher-weight ops drop into earlier bands |
| `disabled` | no scoring — FIFO equivalent |

### Scheduled (delayed) delivery

A `PersistentRefreshMessage` carrying a non-null `deliverAt` (an absolute
Unix timestamp) short-circuits the score strategies: its queue score *is*
its `deliverAt`. Because `get()` reads only members whose score is
`<= now` via ZRANGEBYSCORE, a future-dated message stays invisible until
its due-time elapses, then pops and runs like any other message —
preserving longest-stale-first ordering among all due messages. This is
the primitive the per-operation invalidation cooldown is built on: one
dated refresh per window instead of one per edit (see the cooldown
section of `Persistent-Cache-Flow.md`).

`deliverAt` is encoded as an absolute timestamp rather than a relative
delay so the reaper's score re-derivation (it decodes the stuck
envelope and re-runs `scoreFor`) reproduces the same due-time — a reaped
scheduled message keeps its original schedule instead of drifting later
each reap.

Reaper / visibility timeout: `persistent_refresh_priority_visibility_timeout`
(600s) re-queues messages stuck in `inflight` longer than the
timeout — protection against worker crashes that didn't ack.

## SIGALRM lock renewal

`LockSignalRefresher` registers a SIGALRM handler that calls
`$lock->refresh()` at a configured interval. Process-wide latch
prevents re-arming without disarm. Used by:

- `PersistentRefreshMessageHandler` (refresh lock)
- `PersistentCacheRefreshOnTerminateListener` (legacy inline refresh)
- `PersistentOutputCacheService::acquireColdMissLock`
- `OutputCacheService` (herd-guard lock)

Failure handling: each tick wraps `refresh()` in try/catch and
disarms-after-3-consecutive-failures rather than infinitely retrying
on a broken Redis. Without this, a stuck renewer could extend a
foreign lock after the original TTL expires — the "silent renewal
loop" failure mode seen twice on the SWR hardening branch.

`pcntl` extension required. When unavailable, arm() is a no-op (the
lock then relies on its initial TTL alone — set TTLs accordingly on
pcntl-less platforms).

## Tier and granularity

`Tier` enum (`src/Service/Tier.php`):

| Case | Behavior |
|---|---|
| `HERD_GUARDED` | Standard cache layer enforces herd guard (503 + Retry-After on collision). Worker locks per-op-name. |
| `SWR_ONLY` | No herd guard at standard layer; SWR layer takes over with cold-miss lock + per-query refresh lock. |
| `NEITHER` | Operation is unclassified or absent from `operations:`; persistent cache doesn't apply. |

`Granularity` enum (`src/Service/Granularity.php`):

| Case | Collector tag granularity |
|---|---|
| `SINGLE` | Per-object tags (`obj_<class>_<id>`) so a single object update targets this exact entry |
| `LIST` | Per-class tags (`class_<class>`); any object of the class invalidates the entry |

Both granularities require a non-empty `DependencyCollector` at save
time. **Any** classified op that reaches the write with an empty collector
emits a `collector_empty_on_save` warning — the canonical detector for
missing POST_LOAD coverage (raw-SQL hydrators bypass the subscriber, so
the collector stays empty). A `LIST` op with no tags is as broken as a
`SINGLE` one: its reverse-index entry is never written and invalidation
can never find it.

Per-operation overrides (in `operations:` config block):

- `ttl_override` — freshness TTL for this op
- `enqueue_dedup_ttl_override` — sentinel TTL for this op
- `priority_weight` — warm-class weight: score offset applied per unit for
  invalidation-triggered (speculative) refreshes under the weighted-bands strategy
- `read_priority_weight` — read-class weight: score offset applied per unit for
  demand-driven (read-triggered) refreshes under the weighted-bands strategy;
  defaults to 1 when absent. Reads always sort below warms because the read
  trigger adds a large fixed offset to the score; `read_priority_weight` only
  orders reads among themselves.
- `invalidation_cooldown_ttl` — when set, the invalidation path throttles
  refreshes of this op to once per window via a dated refresh message + the
  `datahub_graphql_op_cooldown_<hash>` sentinel (null = immediate per-edit
  refresh). Used for the coarse translation-verification listings.

Built into `OperationClassifier` at boot; consulted by
`savePersistent` (TTL), the listener (dedupe TTL, cooldown), and the
transport (priority weight).

## Configuration knobs

The bundle config schema lives in
`src/DependencyInjection/Configuration.php`. The persistent-cache
knobs, with bundle defaults:

| Key | Default | Documented purpose |
|---|---|---|
| `persistent_output_cache_enabled` | false | Master switch for the SWR layer |
| `persistent_output_cache_payload_ttl` | 86400 | Payload TTL (1d) |
| `persistent_output_cache_payload_ttl_by_granularity.single` | 86400 | Per-granularity override (1d) |
| `persistent_output_cache_payload_ttl_by_granularity.list` | 1209600 | Per-granularity override (14d) |
| `persistent_output_cache_lifetime` | (inherits `output_cache_lifetime`, 30) | Meta freshness TTL |
| `persistent_refresh_lock_enabled` | true | Refresh lock on/off |
| `persistent_refresh_lock_ttl` | 120 | Refresh lock TTL (worker + inline path) |
| `persistent_refresh_operation_lock_ttl` | 120 | Per-op-name lock TTL for HERD_GUARDED tier |
| `persistent_refresh_queue_enabled` | false | Use Messenger queue vs inline kernel.terminate |
| `persistent_enqueue_dedupe_ttl` | 60 | Dispatch dedupe sentinel TTL |
| `persistent_refresh_priority_strategy` | `oldest_refreshed_at_first` | Queue ordering |
| `persistent_refresh_priority_weight_band_seconds` | 60 | Band width applied per unit of warm or read weight under the weighted-bands strategy |
| `persistent_refresh_priority_max_weight` | 100 | Maximum assignable warm/read weight; bounds the worst-case warm-band span for offset-dominance validation |
| `persistent_refresh_priority_visibility_timeout` | 600 | Stuck-message reaper threshold |
| `persistent_refresh_priority_requeue_score_bump` | 5 | Score penalty on re-send |
| `swr_cold_miss_lock_wait_ms` | 5000 | Cold-miss loser wait |
| `swr_cold_miss_lock_ttl` | 30 | Cold-miss lock TTL |
| `persistent_disable_output_cache_for_guarded` | false | Skip standard cache for SWR-eligible requests |

**Bundle defaults are conservative.** The actual deployment values
live in the installation config (`pimcore-installation/pimcore/config/config.yaml`,
the `pimcore_data_hub.graphql:` block) and the reasoning behind each
override is captured in the comments next to each value. The bundle
side should not be edited to "fix" a deployment — fix the override.

The herd-guard / cold-miss / classifier knobs not listed here are
documented inline in `Configuration.php`.

## Failure modes catalog

| What | Detection | Recovery |
|---|---|---|
| Resolver throws | controller try/catch in worker → log "controller invocation failed" | Sentinel kept (TTL-expires); worker retries via Messenger backoff (3 retries, 1s-2s-4s delays); if all retries fail, message lands in failure queue |
| Resolver returns non-2xx / errors-only / empty / all-null data | `savePersistent` refuses to persist → log "refusing to save non-2xx response" etc. (an errors-set response with no non-null `data` member, the shape a resolver-thrown error produces, is treated as errors-only) | Entry stays STALE on the previous payload; client sees stale-served data |
| Worker dies mid-refresh (OOM, SIGKILL) | Lock TTL expires (Symfony Lock destructor releases on graceful shutdown; on SIGKILL relies on TTL) | Sentinel TTL-expires; next invalidation re-dispatches |
| Sentinel TTL expires while message still queued | Symptom: duplicate refresh messages stack behind the in-flight one | Tune `persistent_enqueue_dedupe_ttl` higher; see Tuning notes in flow doc |
| Lock factory unavailable | Resolver-call warning at worker arm time → "lock factory unavailable; dropping message" | Operator must wire LockFactory; messages silently dropped meanwhile (acked, no work done) |
| Reverse-index entry malformed | `dispatchForTags` per-entry warning, skip | Entry naturally cycles out as its payload ages; loud log surface for debugging |
| `bumpFallbackWatermark(0)` from a fat-fingered caller | Coerced to `time()` inside the method | Defensive — silent freeze prevented |
| Bundle-level reset needed | `clearAll()` drops payload + meta + indices via `TAG_COMMON` clear | Watermark preserved (separate tag) — invalidations during the clear window don't get masked |
| Pimcore POST_UPDATE fires twice on one save | `dispatchForTags` second pass: sentinel present → pending flag, no duplicate dispatch | Worker dispatches one trailing refresh after completion |
| Invalidation arrives during refresh | Listener writes pending flag | Worker reads on completion → one trailing refresh covers all coalesced events |

## Cross-references

Within this repo:
- Bundle config schema: `src/DependencyInjection/Configuration.php`
- Conceptual companion: `doc/Persistent-Cache-Flow.md`
- Pre-existing per-feature docs: `doc/02_Basic_Principle.md`,
  `doc/10_GraphQL/`

Across the workspace:
- Installation override config (tuned values): `repos/yageogroup.com/pimcore-installation/pimcore/config/config.yaml`
  (`pimcore_data_hub.graphql:` block)
- Worker deployment chart: `repos/yageogroup.com/yageo-pimcore-k8s/{dev,local-dev}/charts/datahub-refresh-worker/`
  (`replicas`, `memoryLimit`, `timeLimit`, transport-bound consumer)
- Upstream Rust proxy: `repos/yageogroup.com/pimcore-cache/` — fronts
  this bundle for the public site; has its own two-tier SWR shape
  documented in its CLAUDE.md.
- Wiki overview: `repos/docs/Intellidata.wiki/Projects/yageogroup-com/Pimcore/DataHub-SWR-Cache.md`
- L4 load harness: `repos/docs/Intellidata.wiki/Projects/yageogroup-com/Pimcore/L4-Load-Harness.md`
