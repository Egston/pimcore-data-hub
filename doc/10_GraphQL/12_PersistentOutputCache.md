# Persistent GraphQL Output Cache (SWR)

This adds an additional, persistent GraphQL cache layer to Data Hub that is separate from Pimcore’s `output` tag cache. It enables serving stale responses safely after content changes while a fresh result is recomputed (stale‑while‑revalidate), and keeps the existing Data Hub output cache fully functional.

## Why

- Survive Pimcore `output` tag invalidations and continue serving responses.
- Indicate staleness clearly through headers.
- Keep thundering herd protection in place; by default the persistent layer applies to the same guarded operation names.
- Provide tags and console commands for operational control.

## Behavior Overview

- On request, the persistent cache is checked first.
  - Fresh HIT: returns immediately and refreshes TTL (sliding window).
- Stale HIT: returns a stale response to the caller immediately and schedules a background refresh (after response via kernel.terminate) to update both the standard output cache and the persistent cache.
  - MISS: no change; request proceeds normally and fresh result is stored in both caches as applicable.

## Response Headers

- `X-Pimcore-DataHub-Persistent-Cache: HIT` when response is served from the persistent cache and is fresh.
- `X-Pimcore-DataHub-Persistent-Cache: STALE` when stale was served (SWR). Also includes:
  - `Warning: 110 - "Response is Stale"`
- Existing `X-Pimcore-DataHub-Cache` header continues to reflect the standard output cache HIT/MISS.

## Configuration

Add the following keys to `pimcore_data_hub.graphql` (e.g. in `config.yml`):

```
pimcore_data_hub:
  graphql:
    # existing
    output_cache_enabled: true
    output_cache_lifetime: 30

    # thundering herd protection (existing)
    in_progress_protection_enabled: true
    in_progress_queries: ['ProductsQuery', 'SearchQuery']

    # NEW: persistent layer
    persistent_output_cache_enabled: true
    # optional; defaults to output_cache_lifetime if omitted
    persistent_output_cache_lifetime: 120
    # when true (default), persistent cache applies only to operations listed in in_progress_queries
    persistent_output_cache_guard_only: true
    # large payload TTL (sidecar key), longer than freshness TTL to avoid frequent rewrites
    persistent_output_cache_payload_ttl: 86400
    # optional: dedupe background refresh for non-guarded ops (kernel.terminate path)
    persistent_refresh_lock_enabled: true
    persistent_refresh_lock_ttl: 120
    # queue background refresh to Symfony Messenger instead of kernel.terminate
    persistent_refresh_queue_enabled: false
    # operation-level lock TTL in worker (when herd guard uses operation-name)
    persistent_refresh_operation_lock_ttl: 120
    # enqueue dedupe TTL to avoid flooding queue with identical refresh jobs
    persistent_enqueue_dedupe_ttl: 60

    # skip standard output cache for requests where the persistent layer applies (reduces duplicate work)
    persistent_disable_output_cache_for_guarded: false
```

Notes:
- Set `persistent_output_cache_guard_only: false` to apply the persistent cache to all queries handled by Data Hub.
- Freshness TTL (persistent_output_cache_lifetime) is refreshed on each fresh HIT by updating a small meta key; the large payload is stored under a separate key with a longer TTL to avoid heavy writes per request.

## Tags for Clearing

You can clear via the Pimcore console by tags:

- Common: `datahub_graphql_persistent`
- Per operation: `datahub_graphql_op:<operation>`
- Per client: `datahub_graphql_client:<client>`

Example:

```
bin/console pimcore:cache:clear --tags=datahub_graphql_persistent
bin/console pimcore:cache:clear --tags=datahub_graphql_op:ProductsQuery
bin/console pimcore:cache:clear --tags=datahub_graphql_client:my-client
```

## Invalidation and Staleness

- The persistent layer stores a single “last output invalidation” timestamp internally. On data changes (objects/documents/assets updated/deleted), this timestamp is updated. On each HIT, the cached item’s `refreshedAt` is compared to that timestamp to decide if it’s stale.
- This does not alter Pimcore’s own output cache invalidation; it merely allows the persistent layer to continue serving stale data while a fresh result is recomputed.

### Background refresh deduplication

- For operations listed in `in_progress_queries`, the existing thundering herd guard already deduplicates background refresh calls.
- For other operations, enable a lightweight refresh lock with `persistent_refresh_lock_enabled` (kernel.terminate path). The lock uses a request‑scoped key and is explicitly removed after refresh; the TTL is a safety net if the process dies.

### Queueing background refresh (Messenger)

- Enable `persistent_refresh_queue_enabled` to dispatch a refresh job to Symfony Messenger using transport `datahub_graphql_refresh` (configure in your app). The handler serializes refreshes per operation (when herd guard uses operation name) and recomputes the exact request.
- TTLs:
  - `persistent_refresh_operation_lock_ttl`: TTL for per‑operation lock (seconds). Set slightly above your p99 refresh duration (e.g., 120). Used to ensure one refresh per operation at a time.
  - `persistent_enqueue_dedupe_ttl`: TTL for enqueue dedupe marker (seconds). Prevents flooding the queue with identical refresh jobs when many stale hits arrive. Short window (e.g., 60) is usually sufficient.

Worker deduplication
- Per‑operation serialization (when herd guard uses operation name) via Symfony Lock: resource `datahub_refresh_op:<operationName>`, TTL = `persistent_refresh_operation_lock_ttl`, released after refresh.
- Per‑request deduplication always on the worker via lock resource `datahub_refresh_req:<hash(client + body)>`, ensures no parallel refresh for the same variant even if multiple jobs are queued.

Transport setup (example):

```
# config/packages/messenger.yaml
framework:
  messenger:
    transports:
      datahub_graphql_refresh: '%env(MESSENGER_TRANSPORT_DSN)%'
    routing:
      'Pimcore\\Bundle\\DataHubBundle\\Message\\PersistentRefreshMessage': datahub_graphql_refresh
```

### Lightweight Cache Status Probe

For polling and external cache services, you can obtain cache status without fetching the full response.

- Methods:
  - For POST workflows: send the same GraphQL body but add `?cache_status=1` to the request (recommended for POST).
  - Alternatively, use `HEAD` to the same endpoint. Note: many clients do not send a body with `HEAD`; for GraphQL POST flows prefer `cache_status=1` to ensure status is computed against the same payload.

- Response:
  - Status: `204 No Content` (no body)
  - Headers:
    - `Cache-Status`: includes both layers when applicable, following RFC 9211
      - `pimcore-output; hit|miss|disabled`
      - and, when the persistent layer applies: `pimcore-persistent; hit|stale|miss`
    - `X-Pimcore-DataHub-Cache`: `HIT` or `MISS` (compat)
    - `X-Pimcore-DataHub-Persistent-Cache`: `HIT|STALE|MISS` (compat, only when persistent applies)
    - `Warning: 110 - "Response is Stale"` when the persistent status is `STALE`
    - CORS headers are included

- Side effects: none. Probing does not update TTLs nor schedule refreshes.

- Examples:

```
# POST flow: same GraphQL body, light status-only probe
curl -s -X POST 'https://host/datahub/graphql?cache_status=1' \
  -H 'Content-Type: application/json' \
  --data '{"query":"query Op($id:ID!){node(id:$id){id}}","variables":{"id":123},"operationName":"Op"}' -i

# (Optional) HEAD flow: only if your client can submit an equivalent request context via query string
curl -s -X HEAD 'https://host/datahub/graphql?cache_status=1' -i
```

Notes:
- The persistent layer “applies” only to requests matching its conditions (e.g., `persistent_output_cache_guard_only` with an `operationName` included in `in_progress_queries`). If it does not apply, the `pimcore-persistent` entry is omitted from `Cache-Status` and only the output cache status is returned.
- For POST flows, prefer the `?cache_status=1` probe with the same body to obtain precise status for the exact query and variables.


## Console Commands

- Mark persistent cache as stale (updates the internal timestamp):

```
bin/console datahub:graphql:persistent-cache:mark-output-invalidated
```

- Refresh a specific persistent cache entry by executing a GraphQL request through the same pipeline:

```
bin/console datahub:graphql:persistent-cache:refresh <client> \
  [--operation=OperationName] \
  [--query='query ...'] \
  [--variables='{"key": "value"}'] \
  [--body-file=/path/to/graphQLBody.json]
```

`--body-file` can be used to pass a raw GraphQL JSON payload (including `query`, `variables`, `operationName`).

## Notes

- This layer is designed to be minimally invasive and complementary to the existing output cache and guards.
- If your project exposes a dedicated event for Pimcore’s cache tag invalidation, you can switch the invalidation listener to that event; the current implementation listens to content change events as a robust default.

### Skipping standard output cache for persistent‑guarded requests

- Enable `persistent_disable_output_cache_for_guarded` to bypass the standard output cache for requests where the persistent layer applies (typically your guarded operation names when `persistent_output_cache_guard_only` is true).
- Pros:
  - Reduces duplicate writes/storage and removes one cache layer for those requests.
  - Simplifies invalidation semantics (persistent layer fully owns guarded queries).
- Cons / considerations:
  - OutputCacheEvents (PRE_LOAD/PRE_SAVE) will not fire for those requests; if you rely on listeners to modify responses, they won’t run.
  - Standard output cache TTLs don’t apply; only persistent TTLs are in effect.
  - Output cache metrics for those requests won’t be recorded; use persistent headers instead.
  - If a request doesn’t match persistent “applies” conditions (e.g., missing operationName when guard_only is true), the output cache is still used.
