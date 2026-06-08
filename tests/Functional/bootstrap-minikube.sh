#!/usr/bin/env bash
# Stand up the L3 functional-test namespace against a running Minikube cluster.
#
# Bundle portability: this script lives inside the bundle. Yageo-specific
# Pimcore install paths belong in the host installation repo's deployment
# scripts, not here.
set -euo pipefail

EXPECTED_NAMESPACE="${PIMCORE_FUNCTIONAL_TEST_NAMESPACE:-pimcore-l3-test}"
KUBECTL_CONTEXT="${PIMCORE_FUNCTIONAL_TEST_CONTEXT:-minikube}"
PIMCORE_POD_LABEL="${PIMCORE_FUNCTIONAL_TEST_POD_LABEL:-app.kubernetes.io/name=pimcore,app.kubernetes.io/instance=pimcore-php}"
PIMCORE_POD_ROOT="${PIMCORE_FUNCTIONAL_TEST_POD_ROOT:-/var/www/pimcore}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FIXTURES_DIR="${SCRIPT_DIR}/Fixtures"
CLASS_DEFS_DIR="${FIXTURES_DIR}/class-definitions"

# The pimcore container runs as root and ships no sudo, and the L3 suite
# dispatches in-process (no www-data FPM serving HTTP), so root-owned var/ is
# harmless here — run console commands as the container user directly. Set
# PIMCORE_FUNCTIONAL_TEST_EXEC_USER to drop privileges via sudo on a deployment
# whose pod runs as root WITH sudo present.
EXEC_USER="${PIMCORE_FUNCTIONAL_TEST_EXEC_USER:-}"
if [ -n "${EXEC_USER}" ]; then
    PRIV=(sudo -E -u "${EXEC_USER}")
else
    PRIV=()
fi

require() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "bootstrap-minikube: required tool '$1' not on PATH; aborting." >&2
        exit 1
    fi
}

require kubectl

current_namespace="$(kubectl --context "${KUBECTL_CONTEXT}" config view --minify --output='jsonpath={..namespace}')"
if [ -z "${current_namespace}" ]; then
    current_namespace="default"
fi

if [ "${current_namespace}" != "${EXPECTED_NAMESPACE}" ]; then
    echo "bootstrap-minikube: kubectl context '${KUBECTL_CONTEXT}' is pinned to namespace '${current_namespace}' but the L3 bootstrap requires '${EXPECTED_NAMESPACE}'." >&2
    echo "bootstrap-minikube: set PIMCORE_FUNCTIONAL_TEST_NAMESPACE if you mean to target a different namespace, or run \`kubectl config set-context --current --namespace=${EXPECTED_NAMESPACE}\` to switch." >&2
    exit 1
fi

POD="$(kubectl --context "${KUBECTL_CONTEXT}" -n "${EXPECTED_NAMESPACE}" get pod -l "${PIMCORE_POD_LABEL}" -o jsonpath='{.items[0].metadata.name}')"
if [ -z "${POD}" ]; then
    echo "bootstrap-minikube: no pod matching label '${PIMCORE_POD_LABEL}' found in namespace ${EXPECTED_NAMESPACE}; the L3 test stack is not running." >&2
    exit 1
fi

echo "bootstrap-minikube: targeting namespace=${EXPECTED_NAMESPACE} context=${KUBECTL_CONTEXT} pod=${POD}"

POD_CLASS_DEFS_DIR="${PIMCORE_POD_ROOT}/var/datahub-test-class-definitions"
kubectl --context "${KUBECTL_CONTEXT}" -n "${EXPECTED_NAMESPACE}" exec "${POD}" -- "${PRIV[@]}" mkdir -p "${POD_CLASS_DEFS_DIR}"

for class_name in TestSwrGuardedItem TestSwrOnlyItem TestUncachedItem; do
    src="${CLASS_DEFS_DIR}/${class_name}.json"
    # pimcore:definition:import:class parses the class name from the filename,
    # expecting the class_<Name>_export.json export-naming convention.
    dst="${POD_CLASS_DEFS_DIR}/class_${class_name}_export.json"
    if [ ! -f "${src}" ]; then
        echo "bootstrap-minikube: class definition source missing: ${src}" >&2
        exit 1
    fi
    kubectl --context "${KUBECTL_CONTEXT}" -n "${EXPECTED_NAMESPACE}" cp "${src}" "${POD}:${dst}"
    echo "bootstrap-minikube: importing class definition ${class_name}"
    kubectl --context "${KUBECTL_CONTEXT}" -n "${EXPECTED_NAMESPACE}" exec "${POD}" -- \
        "${PRIV[@]}" env APP_ENV=test php -d memory_limit=2G "${PIMCORE_POD_ROOT}/bin/console" \
        pimcore:definition:import:class --force "${dst}"
done

echo "bootstrap-minikube: rebuilding class shells"
kubectl --context "${KUBECTL_CONTEXT}" -n "${EXPECTED_NAMESPACE}" exec "${POD}" -- \
    "${PRIV[@]}" env APP_ENV=test php -d memory_limit=2G "${PIMCORE_POD_ROOT}/bin/console" \
    pimcore:deployment:classes-rebuild --create-classes

echo "bootstrap-minikube: clearing and warming the Symfony cache so new commands are visible"
kubectl --context "${KUBECTL_CONTEXT}" -n "${EXPECTED_NAMESPACE}" exec "${POD}" -- \
    "${PRIV[@]}" env APP_ENV=test php -d memory_limit=2G "${PIMCORE_POD_ROOT}/bin/console" \
    cache:clear --no-warmup
kubectl --context "${KUBECTL_CONTEXT}" -n "${EXPECTED_NAMESPACE}" exec "${POD}" -- \
    "${PRIV[@]}" env APP_ENV=test php -d memory_limit=2G "${PIMCORE_POD_ROOT}/bin/console" \
    cache:warmup

echo "bootstrap-minikube: seeding default DataHub client"
kubectl --context "${KUBECTL_CONTEXT}" -n "${EXPECTED_NAMESPACE}" exec "${POD}" -- \
    "${PRIV[@]}" env APP_ENV=test php -d memory_limit=2G "${PIMCORE_POD_ROOT}/bin/console" \
    pimcore-data-hub:test:seed-datahub-client

echo "bootstrap-minikube: loading fixture data"
kubectl --context "${KUBECTL_CONTEXT}" -n "${EXPECTED_NAMESPACE}" exec "${POD}" -- \
    "${PRIV[@]}" env APP_ENV=test php -d memory_limit=2G "${PIMCORE_POD_ROOT}/bin/console" \
    pimcore-data-hub:test:load-fixtures

echo "bootstrap-minikube: L3 test namespace ${EXPECTED_NAMESPACE} ready"
