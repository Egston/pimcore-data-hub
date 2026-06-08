# Testing

## Running the test suites

The bundle ships two PHPUnit suites:

| Command | Runs | Requirements |
|---------|------|--------------|
| `vendor/bin/phpunit` | Unit only (the default suite) | none — pure host run |
| `composer ci` | lint (php-cs-fixer + PHPStan) + Unit suite | none |
| `vendor/bin/phpunit --testsuite Unit` | Unit only | none |
| `vendor/bin/phpunit --testsuite Functional` | Functional only | booted Pimcore kernel + Redis + MariaDB |
| `vendor/bin/phpunit --testsuite Unit,Functional` | both | kernel + Redis + MariaDB |

`Unit` is the default suite (`defaultTestSuite` in `phpunit.xml.dist`), so a bare
`phpunit` and `composer ci` stay host-runnable and never pull in the
kernel-bound Functional tests.

### Functional (L3) suite

The Functional suite boots the Pimcore kernel and dispatches GraphQL requests
through the controller against fixtures, so it needs a running stack. Two ways
to stand one up:

- **Minikube namespace:** `tests/Functional/bootstrap-minikube.sh` provisions an
  isolated test namespace against a running cluster. It honours
  `PIMCORE_FUNCTIONAL_TEST_NAMESPACE`, `PIMCORE_FUNCTIONAL_TEST_CONTEXT`,
  `PIMCORE_FUNCTIONAL_TEST_POD_LABEL`, and `PIMCORE_FUNCTIONAL_TEST_POD_ROOT`.
  The per-cluster invocation and any install-specific paths live in the host
  installation repo, not here — this bundle stays deployment-portable.
- **docker-compose:** see `tests/Readme.md` / `bin/init-tests.sh` for the
  self-contained compose stack (it also sets up the database).

Once the stack is up, run `vendor/bin/phpunit --testsuite Functional`. The suite also covers request-validation rejection via `RequestValidationFunctionalTest`; the L3 overlay (`pimcore_data_hub_test.yaml`) enables `request_validation` for the `default` test client with a fixture rules file (`tests/Functional/Fixtures/request-validation-rules.json`) so the validator fires against the real controller.

> **A bare host `--testsuite Functional` fails by design.** With no booted kernel
> the first fixture load calls `Pimcore::getContainer()` on `null`
> (`Pimcore.php:133`). That error is the absence of a stack, not a broken test —
> the Functional suite only runs where the kernel can boot (the minikube namespace
> or the compose stack above).

#### `default` DataHub client seeding

The bootstrap seeds the `default` DataHub GraphQL client via
`pimcore-data-hub:test:seed-datahub-client` (run after `cache:warmup` and before
`load-fixtures`). The client enforces `datahub_apikey` security; `KernelTestCase::sendGraphQL`
attaches `KernelTestCase::TEST_SECURITY_APIKEY` on every dispatch so all sibling
suites (ColdMiss / RefreshQueue / StaleHit / FailureRecovery / PriorityQueue / RequestValidation)
clear security without per-test wiring.

For an end-to-end check against a live Pimcore install, the black-box smoke script
covers the same rejection matrix over real HTTP:
`pimcore-installation/pimcore/bin/datahub-request-validation-smoke`.

## Perform PHPStan Analysis

### data-hub only context

```bash
.github/ci/scripts/setup-pimcore-environment.sh
composer install
vendor/bin/phpstan analyse --memory-limit=-1
```

### Pimcore context

```bash
composer require "phpstan/phpstan:^1.4" --dev
vendor/bin/phpstan analyse -c vendor/pimcore/data-hub/phpstan.neon --memory-limit=-1
```
