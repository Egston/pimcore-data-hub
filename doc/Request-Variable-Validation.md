---
title: Request-variable validation (yageo fork)
---
# Request-variable validation (default-deny variable gate)

> yageo-fork addition. Not present in upstream pimcore/data-hub.

Upstream of every cache layer, the GraphQL endpoint runs a request-level
validator that checks each operation's **variables** against a positive,
contract-derived ruleset before any resolver or cache code executes. Junk input
(scanner probes, typos, fuzzed enum values) becomes an uncacheable HTTP 400
instead of a distinct cache entry that re-resolves on every invalidation for its
whole payload TTL.

This document is the bundle-portable contract: configuration grammar, the rules
JSON schema, default-deny semantics, the reject shape, the development bypass,
and the cache-hygiene drain surfaces. The yageogroup.com deployment specifics
(ConfigMap wiring, the refresh-worker mount, the health-probe allowlist) live in
the workspace wiki — see [Related](#related).

## Activation — opt-in, fail-safe by construction

The engine is a **no-op until two independent preconditions both hold**:

1. a `rules_file` is configured **and** parses, and
2. the requesting client (the DataHub configuration name) is listed in
   `enforced_clients`.

With shipped defaults (`rules_file: ''`, `enforced_clients: []`) the engine
rejects nothing, so the bundle deploys without changing any request's outcome.
A request is rejected only when a rules file is mounted and parses, the client
is enforced, **and** the operation or one of its variables fails a positive
constraint. A malformed *first* load fails to no-op (engine inert), never to
deny-all — availability is preferred over a self-inflicted outage.

## Configuration

All knobs live under `pimcore_data_hub.request_validation`.

```yaml
pimcore_data_hub:
    request_validation:
        # Absolute path to the JSON rules file (typically mounted from a
        # ConfigMap directory). Empty disables the engine entirely.
        rules_file: '%env(DATAHUB_REQUEST_VALIDATION_RULES_FILE)%'

        # DataHub configuration (client) names the validator enforces.
        # Empty enforces no client — the engine stays a no-op.
        enforced_clients:
            - public-content

        # Development / GraphQL-explorer convenience. When a request carries
        # this apikey AND its client is NOT in enforced_clients, validation is
        # skipped and BOTH cache tiers (persistent + output, read and write)
        # are bypassed — the request always hits the resolver fresh, with an
        # audit-log line per bypass. Empty disables the bypass. Comparison is
        # constant-time. Pasting the key on an enforced client does nothing:
        # that client stays fully validated and cached.
        bypass_apikey: '%env(DATAHUB_EXPLORER_BYPASS_APIKEY)%'
```

## Rules JSON schema

```jsonc
{
  "versions": {
    "1": {
      "operations": {
        // Operation name → its allowed variables. An operation absent from a
        // version's `operations` is rejected outright on an enforced client.
        "getResourceLibraryAssetItemListing": {
          "variables": {
            // Every variable the operation may carry MUST be declared.
            // An undeclared variable present in the request is rejected.
            "defaultLanguage": { "kind": "enum", "values": ["en", "de", "ja", "zh", "zh_Hant_TW"] },
            "filter":    { "kind": "null" },
            "first":     { "kind": "null" },
            "sortBy":    { "kind": "null" },
            "sortOrder": { "kind": "null" }
          }
        },
        // An operation with no variables declares an empty object.
        "getSomethingWithNoVars": { "variables": {} }
      }
    },
    "2": {
      // A version inherits its parent, with per-operation override and
      // per-operation removal (declare the op as null to remove it).
      // Inheritance is flattened at load time.
      "inherits": 1
    }
  }
}
```

### Constraint kinds

Each variable declares exactly one `kind`. The value the request sends must
satisfy it, or the request is rejected with `constraint_failed`.

| `kind`    | Extra fields                       | Matches |
| --------- | ---------------------------------- | ------- |
| `enum`    | `values` (non-empty scalar array)  | value is one of `values` (strict `in_array`) |
| `const`   | `value` (scalar or null)           | value strictly equals `value` |
| `null`    | —                                  | value is exactly `null` |
| `int`     | `min?`, `max?`, `nullable?`        | canonical integer within `[min, max]`; `null` allowed iff `nullable` |
| `string`  | `nullable?`, `prefix?`             | safe-charset string (`[A-Za-z0-9 _\-/.]+`), optional required `prefix`; `null` allowed iff `nullable` |
| `csv-int` | —                                  | comma-separated integers, unbounded (IN-clause over an indexed int column) |

An unknown `kind`, an empty `enum`, or a non-scalar `enum` member is a rules
**parse** error (the file is rejected, last-known-good retained), not a
per-request reject.

### Version resolution

A request carries its rules version via the `?version=N` query parameter
(shared wire parameter, distinct counters — see
[The `?version=N` parameter](#the-versionn-parameter)). Resolution:

- exact match → that version's flattened ruleset;
- **missing, unknown, or over-high → the latest version** (never a bypass);
- the parameter absent → latest.

## Default-deny semantics

On an enforced client, a request is rejected (HTTP 400) when **any** holds:

- the operation is not declared in the resolved version → `operation_not_allowed`;
- the request carries a variable not declared for that operation → `undeclared_variable`;
- a declared variable's value fails its constraint → `constraint_failed`.

"Declared but absent" is also default-deny: a non-nullable declared variable
that the request omits feeds `null` to the constraint and fails it. Only a
`null`-kind or `nullable: true` constraint accepts an absent variable.

## Reject shape

A rejected request returns **HTTP 400** with a GraphQL-shaped `errors` body,
rendered **outside** the executor catch so it is never admitted to any cache
layer, and emits a distinct log slug with a truncated reason context (operation,
reason code, the offending variable). It is a client-safe error: no stack, no
internal detail.

## Hot reload, last-known-good, storm suppression

The loader re-`stat`s the rules JSON on each call and re-parses only on mtime
change — steady-state cost is a single `stat`. A warm parse failure (a malformed
edit to a live file) logs **once per failing mtime** at ERROR and keeps serving
the previous good ruleset; it does not re-log on every subsequent request for
the same bad file. The success path re-arms the signal, so a later failure is
loud again. A cold first-load failure logs and leaves the engine inert.

## The `?version=N` parameter

`?version=N` is one wire parameter consumed by three independent version
counters that advance on different cadences:

- the **rules** `"versions"` counter (this document);
- the frontend `GRAPHQL_QUERIES_VERSION`;
- the `pimcore-cache` Redis namespace version.

They share the parameter but are not coupled — a bump to one does not imply a
bump to the others.

## Cache hygiene — draining already-polluted entries

Entries written before enforcement (or by out-of-band injection) are removed by
three surfaces:

| Surface | When it fires | What it does |
| --- | --- | --- |
| **Refresh self-clean** | Invalidation-triggered refresh | The worker re-validates the stored canonical body against current rules *before* acquiring the per-op / per-hash refresh lock; a non-conforming entry is evicted (payload, meta, forward index, every reverse index named by its stored tag set) and the message dropped — a polluting entry never holds the lock. |
| **Rule sweep** | On demand (console) / maintenance task | Walks the full entry index and re-validates every stored entry — catches entries whose tags never invalidate (probe traffic) that self-clean can't reach. Per-entry classification: `not_enforced` / `passed` / `evicted` / `undecodable_canonical` (evicted with warning) / `validate_failed` (conformance undeterminable — blocks the change-stamp so the next cycle retries). |
| **All-null admission gate** | Every persistent-cache write | A resolver-thrown error nulls its field but keeps the `data` key; the gate requires at least one non-null `data` member, so an error response produced by rejected input never enters the cache. |

The sweep is exposed as a console command and a stamp-gated Pimcore maintenance
task:

```bash
# One-shot: re-validate every persistent-cache entry, evict non-conforming.
# Exits non-zero when any eviction failed, so operator scripting can detect an
# incomplete drain.
bin/console datahub:graphql:persistent-cache:purge-invalid
```

The maintenance task re-runs automatically after every rules change (its
change-stamp is the rules-file mtime + the enforced-clients hash). The stamp
advances only on a clean sweep (no eviction failures and no undeterminable
entries), so a transient failure forces a retry on the next cycle.

## Resolver-side tightening

Independently of the variable gate, the listing resolvers reject `sortOrder`
without `sortBy`, and any `sortOrder` other than `ASC` / `DESC`, with a
client-safe GraphQL error rather than silently ignoring it.

## Code map

| Concern | Class |
| --- | --- |
| Rules file load / mtime reload / last-known-good | `Service\RequestValidation\RulesLoader` |
| Version-resolved, inheritance-flattened ruleset | `Service\RequestValidation\RulesSet`, `RulesVersion`, `OperationRule` |
| One variable's allowed shape | `Service\RequestValidation\VariableConstraint` |
| Per-request gate (wired into `WebserviceController::webonyxAction`) | `Service\RequestValidation\RequestVariableValidator` |
| Persistent-cache re-validation sweep | `Service\RequestValidation\PersistentCacheRuleSweep`, `PersistentCacheRuleSweepTask`, `SweepCounts` |
| Console drain command | `Command\PersistentCachePurgeInvalidCommand` |

## Related

- [Two-tier SWR cache](../README.md#two-tier-swr-cache-yageo-fork) — the cache layer this gate sits in front of.
- Deployment wiring (ConfigMap, refresh-worker mount, health-probe allowlist) — workspace wiki, *DataHub Request Validation* under Projects → yageogroup-com → Pimcore.
