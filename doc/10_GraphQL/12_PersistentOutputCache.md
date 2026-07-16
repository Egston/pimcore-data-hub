# Persistent GraphQL Output Cache (SWR)

A persistent, stale-while-revalidate GraphQL cache layer for Data Hub, separate from Pimcore's `output` tag cache. It serves a cached response immediately — even a knowingly-stale one after a content change — and recomputes a fresh result asynchronously, so visitor requests never block on a cold recompute and survive `output` tag invalidations.

> **This page is a feature-level overview.** The authoritative internals — the code map, the per-query reverse index, the fallback watermark, the tier model, the priority-transport refresh worker, the invalidation cooldown, and the failure-mode catalog — live in the two deep-dive docs, which are kept current:
>
> - [`../Persistent-Cache-Architecture.md`](../Persistent-Cache-Architecture.md) — code map, key/tag namespaces, priority transport (incl. scheduled delivery), lock spaces, per-operation config knobs.
> - [`../Persistent-Cache-Flow.md`](../Persistent-Cache-Flow.md) — the runtime flow: states, the Redis keys per query, the sentinels, the watermark, the invalidation cooldown.
>
> When this page and a deep-dive disagree, the deep-dive wins.

## Behaviour overview

- **Fresh HIT** — returned immediately; the freshness TTL is renewed (sliding window).
- **Stale HIT** — the stale response is returned to the caller immediately (SWR) and a background refresh is scheduled to recompute it.
- **MISS** — the request proceeds normally and the fresh result is stored.

Background refreshes run on a dedicated Messenger **refresh-worker** pod draining the `datahub_graphql_refresh` priority transport (a scheduled sorted-set queue, safe at `replicas ≥ 2`), not inline on the request. See the deep-dives for the transport, the per-entry refresh lock, and the `FrontendRequestScope` under which worker writes run.

## Response headers

- `X-Pimcore-DataHub-Persistent-Cache: HIT` — served fresh from the persistent cache.
- `X-Pimcore-DataHub-Persistent-Cache: STALE` — a stale response was served (SWR); also carries `Warning: 110 - "Response is Stale"`.
- `X-Pimcore-DataHub-Cache: HIT|MISS` — the standard output cache status (unchanged).

### Cache-status probe

To obtain cache status without fetching the full response (for polling / external cache services), add `?cache_status=1` to the GraphQL POST (same body). Response: `204 No Content` with a `Cache-Status` header (RFC 9211) reporting both layers — `pimcore-output; hit|miss|disabled` and, when the persistent layer applies, `pimcore-persistent; hit|stale|miss`. Probing has no side effects (no TTL renewal, no refresh scheduled).

## Configuration

Under `pimcore_data_hub.graphql` (see `pimcore-installation/pimcore/config/config.yaml` for the live values, and Architecture.md § config knobs for the full list):

```yaml
pimcore_data_hub:
    graphql:
        output_cache_enabled: true               # standard output cache
        output_cache_lifetime: 2592000

        persistent_output_cache_enabled: true     # this layer
        persistent_refresh_queue_enabled: true    # async refresh via the priority transport
        persistent_output_cache_payload_ttl: 86400
        persistent_output_cache_payload_ttl_by_granularity: { ... }
        persistent_disable_output_cache_for_guarded: true  # herd_guarded ops are owned by this layer

        in_progress_protection_enabled: true      # herd guard (in-progress lock) for tier=herd_guarded

        # Per-operation classification — the single source of truth for
        # persistent-cache membership, herd-guard participation, and tagging.
        operations:
            someListingOperation:
                tier: herd_guarded                # in-progress lock + SWR
                granularity: list                 # class-only invalidation tags
            someDetailOperation:
                tier: swr_only                    # SWR, no lock
                granularity: single               # per-object-id tags
                # ttl_override / priority_weight / invalidation_cooldown_ttl optional
```

Membership and behaviour are driven entirely by the `operations:` map. The earlier `persistent_output_cache_guard_only` flag and the `in_progress_queries` allowlist have been removed — every listed operation participates in the persistent cache; `tier` decides only whether it also takes the in-progress lock.

## Tags for clearing

Cleared via the Pimcore console by tag. The current families:

- `datahub_graphql_persistent` — common tag on every entry.
- `datahub_graphql_op:<operation>` — per operation.
- `datahub_graphql_client:<client>` — per client.
- per-class and per-object-id tags — attached per `granularity` (`list` → class, `single` → object id) so an edit invalidates only the dependent entries via the reverse index.

```
bin/console pimcore:cache:clear --tags=datahub_graphql_persistent
bin/console pimcore:cache:clear --tags=datahub_graphql_op:getResourceLibraryArticleItemListing
```

## Console commands

```
datahub:graphql:persistent-cache:mark-output-invalidated   # bump the fallback watermark (stale everything)
datahub:graphql:persistent-cache:refresh <client> [--operation=] [--query=] [--variables=] [--body-file=]
datahub:graphql:persistent-cache:clear                     # drop persistent entries
datahub:graphql:persistent-cache:purge-invalid [--dry-run] # evict entries that no longer satisfy the request-validation rules
```

## Invalidation

On a content change, the invalidation listener resolves which cached entries depend on the changed element (via the reverse index, keyed by the per-class / per-object tags above) and dispatches a targeted refresh for each — rather than storing a single global "last invalidation" timestamp. A **fallback watermark** is the safety floor: when a change cannot be attributed to specific entries (a non-element event, the refresh queue disabled, or no indexed dependants), the watermark is bumped and the entire persistent cache is treated as stale. Watermark bumps are logged at WARNING because a bump triggers a cluster-wide refresh. The exact decision tree, the cooldown throttle for coarse listings, and the storm failure modes are in Persistent-Cache-Flow.md.
