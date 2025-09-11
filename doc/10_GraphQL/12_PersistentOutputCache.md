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
    # optional: dedupe background refresh for non-guarded ops
    persistent_refresh_lock_enabled: true
    persistent_refresh_lock_ttl: 120
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
- For other operations, enable a lightweight refresh lock with `persistent_refresh_lock_enabled`. The lock uses a request‑scoped key and is explicitly removed after refresh; the TTL is a safety net if the process dies.

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
