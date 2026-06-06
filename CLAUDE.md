# pimcore-data-hub

Before working on persistent-cache (two-tier SWR) behavior, read these in order:

1. [`doc/Persistent-Cache-Flow.md`](doc/Persistent-Cache-Flow.md) — the runtime
   flow: states, the three Redis keys per query, the dedupe/pending/cooldown
   sentinels, the watermark, and the invalidation cooldown throttle.
2. [`doc/Persistent-Cache-Architecture.md`](doc/Persistent-Cache-Architecture.md)
   — the code map, key/tag namespaces, priority transport (including scheduled
   delivery), lock spaces, and per-operation config knobs.

Host-runnable gate: `composer cs:check && composer stan && vendor/bin/phpunit --testsuite=Unit`.
The `Functional` suite boots the Pimcore kernel and needs minikube/docker-compose
(see `tests/Functional/bootstrap-minikube.sh`); it is opt-in, not part of the host gate.

**Do not add the Pimcore GmbH license header to new files.** Pimcore GmbH is not the
author of files created in this fork, licensing is governed by the root `LICENSE`, and
the header's pimcore.org license link is stale (upstream has since relicensed away from
GPL). Existing upstream files keep their original headers; the `header_comment` cs-fixer
rule was removed so the fixer no longer inserts it.
