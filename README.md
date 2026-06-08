---
title: Datahub
---
# Pimcore Datahub

> YAGEO fork of [pimcore/data-hub](https://github.com/pimcore/data-hub), maintained
> under GPLv3+ only — see [LICENSE.md](LICENSE.md). Upstream relicensed to POCL after
> the fork point; upstream code from post-relicense versions must not be merged here.

[<img src="https://sonarcloud.io/images/project_badges/sonarcloud-light.svg" alt="SonarQube Cloud" height="30" />](https://sonarcloud.io/summary/new_code?id=pimcore_data-hub)


Pimcore Datahub (data delivery and consumption platform) integrates different input & output channel
technologies into a simple & easy-to-configure system on top of Pimcore.

The basic configuration of Datahub comes with a GraphQL API, which is described in the next sections of this documentation. To use another configuration, Pimcore Datahub can be extended with different adapters (see [Further Information](#further-information)).

![Overview](./doc/img/overview.jpg)
*Sample presentation of Datahub config when choosing the GraphQL endpoint*

A short introduction video of an output channel based on the GraphQL query language can be found [here](./doc/img/graphql/intro.mp4).

## Features in a Nutshell
- Easy-to-configure interface layer for data delivery and consumption
- Tool of choice to connect Pimcore to any other systems and applications besides internal PHP API - whether they are backend applications like ERP systems or frontend applications like your storefront
- Multiple endpoints definition for different use cases and target/source systems
- Central and easy-to-use GUI to transform and prepare data for defined endpoints
- To-be-exposed data restriction to endpoints by defining workspaces and schemas.

## Documentation Overview
- [Installation](./doc/01_Installation_and_Upgrade/README.md)
- [Basic principle](./doc/02_Basic_Principle.md) for configuring an endpoint
- [GraphQL](./doc/10_GraphQL/README.md) [*default and recommended endpoint*]
- [Configuration & Deployment](./doc/20_Deployment.md)
- [Testing](./doc/30_Testing.md)

yageo-fork additions (not in upstream):
- [Request-variable validation](./doc/Request-Variable-Validation.md) — default-deny GraphQL variable gate (see [§ below](#request-variable-validation-yageo-fork))
- [Persistent Cache Flow](./doc/Persistent-Cache-Flow.md) / [Persistent Cache Architecture](./doc/Persistent-Cache-Architecture.md) — SWR cache deep dive (see [§ below](#two-tier-swr-cache-yageo-fork))

## Two-tier SWR cache (yageo fork)

This fork ships a stale-while-revalidate (SWR) persistent cache layer on top of
the standard GraphQL output cache. Operations are classified into one of three
tiers and the runtime behavior follows from that classification.

### Overview

Two operationally-distinct consumer profiles share the persistent layer:

| Tier            | Membership                                                                    | Cold miss                                                | Stale hit                              | Refresh dedup        |
| --------------- | ----------------------------------------------------------------------------- | -------------------------------------------------------- | -------------------------------------- | -------------------- |
| `HERD_GUARDED`  | `operations: { <op>: { tier: herd_guarded, … } }` (BC: `in_progress_queries`) | 503 + `Retry-After`                                      | Serve stale + enqueue refresh          | by `operationName`   |
| `SWR_ONLY`      | `operations: { <op>: { tier: swr_only, … } }`                                 | Run resolver inline (bounded-wait per-query-hash lock)   | Serve stale + enqueue refresh          | by canonical body hash |
| `NEITHER`       | not listed in `operations`                                                    | Run resolver inline; standard output cache only          | n/a                                    | n/a                  |

The two SWR tiers differ only in cold-miss policy and refresh-lock granularity.
`HERD_GUARDED` is intended for retry-aware programmatic consumers where bounded
latency under cold start matters more than always-on availability; `SWR_ONLY`
is intended for browsers where a 503 is unacceptable. Operations omitted from
the classification do not participate in the persistent layer at all.

### Configuration reference

All knobs live under `pimcore_data_hub.graphql`. Per-operation classification
is the only required surface; the rest have defaults.

```yaml
pimcore_data_hub:
    graphql:
        persistent_output_cache_enabled: true

        # Tier-level payload TTL defaults; per-operation `ttl_override` wins.
        persistent_output_cache_payload_ttl_by_granularity:
            single: 86400      # 1 day
            list:   1209600    # 2 weeks

        # Skip the standard output cache (read + write) for ops that have
        # their own SWR layer — avoids storing the response twice in Redis.
        persistent_disable_output_cache_for_guarded: true

        # Per-operation classification. Each entry declares both `tier` and
        # `granularity` explicitly; unknown keys under an `operations` entry reject at boot.
        operations:
            getMyGuardedListing:
                tier: herd_guarded
                granularity: list
            getMyBrowserListing:
                tier: swr_only
                granularity: list
            getMyBrowserItem:
                tier: swr_only
                granularity: single
                ttl_override: 600              # override the granularity default
                enqueue_dedup_ttl_override: 120 # override `persistent_enqueue_dedupe_ttl`
                priority_weight: 5             # consumed by weight-banded strategy

        # Refresh queue / Messenger integration.
        persistent_refresh_queue_enabled: true
        persistent_enqueue_dedupe_ttl: 60        # per-op dedupe window
        persistent_refresh_priority_strategy: oldest_refreshed_at_first
        # Accepted values:
        #   `oldest_refreshed_at_first` (default) — score = refreshedAt
        #   `oldest_refreshed_at_first_with_weight_bands` — score = refreshedAt
        #       - (priority_weight * persistent_refresh_priority_weight_band_seconds)
        #   `disabled` — insertion-order FIFO equivalent
        persistent_refresh_priority_weight_band_seconds: 60

        # SWR_ONLY cold-miss bounded wait.
        swr_cold_miss_lock_wait_ms: 5000

        # ─── BC alias (preserved indefinitely) ───
        # Each entry folds into `operations` as the synthetic shape
        #   { tier: herd_guarded, granularity: list,
        #     ttl_override: null, enqueue_dedup_ttl_override: null,
        #     priority_weight: 1 }
        # If an operation name appears in BOTH `in_progress_queries` and an
        # explicit `operations:` entry, the explicit entry wins and the bundle
        # emits `pimcore_data_hub.operations_in_progress_conflict` at WARNING
        # on boot, enumerating the conflicting names.
        in_progress_queries:
            - getMyGuardedListing
```

### Messenger transport contract

Background refresh runs off a Symfony Messenger transport so refresh work does
not pin FPM workers. The bundle ships the transport factory; the host
application binds the transport id and DSN in its `messenger.yaml`.

| Surface                 | Value                                                                  |
| ----------------------- | ---------------------------------------------------------------------- |
| Message FQCN            | `Pimcore\Bundle\DataHubBundle\Message\PersistentRefreshMessage`        |
| Transport DSN scheme    | `datahub-priority-redis://<host>:<port>/<db>`                          |
| Recommended transport id| `datahub_graphql_refresh`                                              |
| Consumer invocation     | `bin/console messenger:consume datahub_graphql_refresh --memory-limit=512M --time-limit=3600` |

Wire both the transport and the routing in the host's `config/packages/messenger.yaml`:

```yaml
framework:
    messenger:
        transports:
            datahub_graphql_refresh:
                dsn: 'datahub-priority-redis://<host>:<port>/<db>'
                retry_strategy:
                    max_retries: 3
                    delay: 1000
                    multiplier: 2
        routing:
            'Pimcore\Bundle\DataHubBundle\Message\PersistentRefreshMessage': datahub_graphql_refresh
```

The transport is a Redis-backed priority queue (ZSET keyed by `refreshedAt`,
oldest-stale first). The DSN factory consults the `pass` segment of the DSN
first and falls back to `REDIS_PASSWORD` when none is supplied — Symfony ships
no `urlencode:` env-var processor, so embedding a password with URL-reserved
characters into the DSN at YAML level is not possible; let the factory resolve
it from env.

**Retry strategy.** Configure the transport in the host's `messenger.yaml`
under `retry_strategy` — the bundle does not impose one. A sensible default is
`max_retries: 3` with exponential backoff (`delay: 1000`, `multiplier: 2`)
yielding 1s/2s/4s. Throwing `Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException`
from the handler signals contention (another consumer holds the per-op or
per-hash lock) and triggers a requeue.

**Redis prefix families on the consumer's Redis.** Keep them in
mind when sizing or inspecting Redis:

| Prefix                         | Owner                          | Role                                                         |
| ------------------------------ | ------------------------------ | ------------------------------------------------------------ |
| `datahub_inprogress_*`         | Pimcore Cache marker           | Herd-guard cold-start bookkeeping; cache-marker subsystem.   |
| `datahub_inprogress:*`         | Symfony Lock resource          | Atomic per-`operationName` (or per-canonical-request) lock.  |
| `datahub_refresh_priority_*`   | Priority transport             | ZSET (`_queue`) + messages HASH + inflight-visibility HASH.  |

The underscore-suffixed and colon-suffixed `datahub_inprogress` prefixes are
**not** the same subsystem and must not be conflated when writing new lock
helpers — anchor against `OutputCacheService::computeOperationLockKey()`
(colon, Symfony Lock) when serializing by operationName, and against
`OutputCacheService::lockKeyFor()` (underscore, Pimcore Cache marker) for the
cache-marker site. Two additional auxiliary prefixes also exist on the same
Redis instance: `datahub_persistent_refresh_lock_*` for the SWR refresh lock
acquired on the enqueue path and re-checked by the handler, and
`datahub_swr_cold_miss_*` for the bounded-wait cold-miss lock used by `SWR_ONLY`
operations.

### Sizing guidance

The bundle does not prescribe a deployment topology. Run **one Messenger
consumer process per consumer pod**; replica count, container image, and
scheduling are deployment concerns out of scope here.

Consumer-pod sizing depends on:

- The resolver-completion-time distribution across the classified operations
  the consumer handles. Per-`operationName` serialization (for `HERD_GUARDED`)
  and per-canonical-body serialization (for `SWR_ONLY`) cap intra-key
  parallelism; cross-operation parallelism is bounded by the number of
  consumer processes.
- The product of `payload_ttl` and the peak invalidation rate, which drives
  the steady-state Redis memory floor for the cached payloads.
- The `persistent_enqueue_dedupe_ttl` floor on per-operation refresh cadence:
  combined with cache-key-based hashing, re-saving the same content
  repeatedly only enqueues one refresh per cache key per dedupe window.
  Refresh-queue load is wave-shaped — proportional to
  `(distinct_classes × cache_key_fanout) / dedupe_ttl` over the wave period,
  not constant-rate.

`payload_ttl` is a tunable. The default of one day balances Redis memory
against the cost of regenerating cold-evicted entries; longer values reduce
regeneration but extend the Redis memory floor proportionally to write rate.

### Operator runbook — `REDIS_PASSWORD` rotation

The transport factory reads `REDIS_PASSWORD` once at consumer process startup
when the DSN does not carry the credential inline. Any deployment that wires
the consumer pod via `envFrom` over a Kubernetes secret (or any other
load-on-boot mechanism) MUST restart the consumer pods on rotation —
`kubectl rollout restart`, a `checksum/secret` annotation on the pod spec,
or whatever the deployment shape supports. The bundle does not auto-detect
credential rotation.

### What lives in the deployment

Deployment-side concerns are intentionally out of scope for this README and
live in the deployment overlay (Helm charts, Kustomize bases, systemd units,
or equivalent): consumer pod chart name, replica count, resource limits,
namespace, secret wiring, and the queue-drain policy during partial outages.
In particular, do **not** add the bundle's transport name to an unrelated
maintenance worker's queue-list flag to "drain in the interim" — the
operational drift produced on credential rotation or worker failure is worse
than a temporarily-accumulating queue.

## Request-variable validation (yageo fork)

Upstream of every cache layer, the GraphQL endpoint runs a default-deny
request-level validator that checks each operation's **variables** against a
positive, contract-derived ruleset before any resolver or cache code executes.
Junk input (scanner probes, fuzzed enum values) becomes an uncacheable HTTP 400
instead of a distinct cache entry that re-resolves on every invalidation for its
whole payload TTL.

The engine is a **no-op until** a `rules_file` is mounted **and** the requesting
client is listed in `enforced_clients` — so it deploys without changing any
request's outcome; enforcement is opt-in per client.

```yaml
pimcore_data_hub:
    request_validation:
        rules_file: '%env(DATAHUB_REQUEST_VALIDATION_RULES_FILE)%'  # empty = engine off
        enforced_clients: ['public-content']                       # empty = enforce nothing
        bypass_apikey: '%env(DATAHUB_EXPLORER_BYPASS_APIKEY)%'      # dev/explorer; non-enforced clients only
```

Rules are versioned JSON (inheritance flattened at load; unknown version →
latest), hot-reloaded on mtime change with last-known-good retention, and each
variable carries one constraint (`enum` / `const` / `null` / `int` / `string` /
`csv-int`). A rejected request is rendered outside the executor catch so it is
never cached. Entries written before enforcement are drained by three surfaces
(refresh self-clean, the `datahub:graphql:persistent-cache:purge-invalid` sweep,
and the all-null admission gate).

Full contract — config grammar, the rules JSON schema, default-deny semantics,
the bypass, and the drain surfaces — is in
[Request-variable validation](./doc/Request-Variable-Validation.md).

## Further Information
On Pimcore Datahub adapters:
- [Datahub Simple Rest API](https://pimcore.com/docs/platform/Datahub_Simple_Rest/)
- [Datahub File Export](https://pimcore.com/docs/platform/Datahub_File_Export/)
- [Datahub Productsup](https://pimcore.com/docs/platform/Datahub_Productsup/)
- [Datahub CI Hub](https://pimcore.com/docs/platform/Datahub_CI_Hub/)
  
## Contributions
As Pimcore Datahub is a community project, any contributions highly appreciated.
For details see our [Contributing guide](https://github.com/pimcore/data-hub/blob/master/CONTRIBUTING.md).
