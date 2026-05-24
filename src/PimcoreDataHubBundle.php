<?php

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Bundle\DataHubBundle;

use Pimcore\Bundle\AdminBundle\PimcoreAdminBundle;
use Pimcore\Bundle\DataHubBundle\DependencyInjection\Compiler\CustomDocumentTypePass;
use Pimcore\Bundle\DataHubBundle\DependencyInjection\Compiler\ImportExportLocatorsPass;
use Pimcore\Bundle\DataHubBundle\DependencyInjection\Configuration;
use Pimcore\Bundle\DataHubBundle\Service\OperationClassifier;
use Pimcore\Extension\Bundle\AbstractPimcoreBundle;
use Pimcore\Extension\Bundle\Installer\InstallerInterface;
use Pimcore\Extension\Bundle\PimcoreBundleAdminClassicInterface;
use Pimcore\Extension\Bundle\Traits\BundleAdminClassicTrait;
use Pimcore\Extension\Bundle\Traits\PackageVersionTrait;
use Pimcore\HttpKernel\Bundle\DependentBundleInterface;
use Pimcore\HttpKernel\BundleCollection\BundleCollection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

class PimcoreDataHubBundle extends AbstractPimcoreBundle implements PimcoreBundleAdminClassicInterface, DependentBundleInterface
{
    use BundleAdminClassicTrait;
    use PackageVersionTrait;

    const RUNTIME_CONTEXT_KEY = 'datahub_context';

    const NOT_ALLOWED_POLICY_EXCEPTION = 1;

    const NOT_ALLOWED_POLICY_NULL = 2;

    //TODO decide whether we want to return null here or throw an exception (maybe make this configurable?)
    public static $notAllowedPolicy = self::NOT_ALLOWED_POLICY_NULL;

    public function build(ContainerBuilder $container)
    {
        $container->addCompilerPass(new ImportExportLocatorsPass());
        $container->addCompilerPass(new CustomDocumentTypePass());
    }

    /**
     * Informational logging for the operation-classification config tree.
     * Validator closures run at container compile time and cannot emit log lines
     * reliably, so sentinel keys stashed by the validator are read here instead.
     * Resolves the PSR logger from the container; bundle boot ordering ensures
     * MonologBundle has loaded.
     */
    public function boot(): void
    {
        parent::boot();

        if (!$this->container || !$this->container->hasParameter('pimcore_data_hub')) {
            return;
        }
        $cfg = $this->container->getParameter('pimcore_data_hub');
        if (!is_array($cfg)) {
            return;
        }
        $graphql = $cfg['graphql'] ?? [];
        if (!is_array($graphql)) {
            return;
        }
        $inProgress = $graphql['in_progress_queries'] ?? [];
        $operations = $graphql['operations'] ?? [];
        if (!is_array($inProgress) || !is_array($operations)) {
            return;
        }

        $logger = $this->container->get('logger');

        try {
            $this->container->get(OperationClassifier::class);
            $classifierPresent = true;
        } catch (ServiceNotFoundException) {
            $classifierPresent = false;
        } catch (\Throwable $e) {
            $classifierPresent = false;
            $logger->error(sprintf(
                'pimcore_data_hub.classifier_boot_failed: %s: %s',
                $e::class,
                $e->getMessage()
            ));
        }

        $this->runBootDiagnostics($graphql, $classifierPresent, $logger);
    }

    /**
     * @param array<string, mixed> $graphql
     */
    public function runBootDiagnostics(array $graphql, bool $classifierPresent, LoggerInterface $logger): void
    {
        $inProgress = $graphql['in_progress_queries'] ?? [];
        $inProgressNames = array_values(array_filter($inProgress, static fn ($v) => is_string($v) && $v !== ''));
        if ($inProgressNames !== []) {
            $logger->info(sprintf(
                'pimcore_data_hub.in_progress_queries_deprecated: %d entries (%s) fold into operations as { tier: herd_guarded, granularity: list }; consider migrating to the explicit operations config tree',
                count($inProgressNames),
                implode(', ', $inProgressNames)
            ));
        }

        $conflicts = $graphql['_in_progress_operations_conflicts'] ?? [];
        if (is_array($conflicts) && $conflicts !== []) {
            $logger->warning(sprintf(
                'pimcore_data_hub.operations_in_progress_conflict: operationNames declared in both in_progress_queries and operations — explicit operations entry wins: %s',
                implode(', ', $conflicts)
            ));
        }

        if (!empty($graphql['_persistent_output_cache_guard_only_set'])) {
            $logger->warning('pimcore_data_hub.persistent_output_cache_guard_only_removed: key has no effect — the single-surface classifier gate is always active; remove the key from your config');
        }

        // Warn when deprecated in_progress_* scalar keys are present in config.
        // The validator folds them into herd_guard_* at compile time; the non-null
        // check is correct because defaultNull() causes the key to always appear in
        // the processed config (testing === null distinguishes "not set" from "set to null").
        $deprecatedFound = [];
        foreach (Configuration::HERD_GUARD_ALIASES as $old => $new) {
            $val = $graphql[$old] ?? null;
            if ($val !== null && $val !== '') {
                $deprecatedFound[] = sprintf('%s → %s', $old, $new);
            }
        }
        if ($deprecatedFound !== []) {
            $logger->warning(sprintf(
                'pimcore_data_hub.herd_guard_keys_deprecated: deprecated config keys in use — migrate to canonical equivalents: %s',
                implode('; ', $deprecatedFound)
            ));
        }

        $aliasConflicts = $graphql['_herd_guard_alias_conflicts'] ?? [];
        if (is_array($aliasConflicts) && $aliasConflicts !== []) {
            $logger->warning(sprintf(
                'pimcore_data_hub.herd_guard_alias_conflict: both deprecated alias and canonical key set — canonical wins: %s',
                implode('; ', $aliasConflicts)
            ));
        }

        $enabledVal = $graphql['herd_guard_enabled'] ?? $graphql['in_progress_protection_enabled'] ?? null;
        $herdGuardActive = ($enabledVal !== null) && filter_var($enabledVal, FILTER_VALIDATE_BOOLEAN);
        if ($herdGuardActive && !$classifierPresent) {
            $logger->error('pimcore_data_hub.herd_guard_no_classifier: herd_guard_enabled is true but OperationClassifier is not wired in the container — classifier-path membership will always return false; wire via DI');
        }
    }

    public static function registerDependentBundles(BundleCollection $collection): void
    {
        $collection->addBundle(new PimcoreAdminBundle(), 60);
    }

    protected function getComposerPackageName(): string
    {
        return 'pimcore/data-hub';
    }

    public function getCssPaths(): array
    {
        return [
            '/bundles/pimcoredatahub/css/icons.css',
            '/bundles/pimcoredatahub/css/style.css',
        ];
    }

    public function getJsPaths(): array
    {
        return [
            '/bundles/pimcoredatahub/js/datahub.js',
            '/bundles/pimcoredatahub/js/config.js',
            '/bundles/pimcoredatahub/js/adapter/abstract.js',
            '/bundles/pimcoredatahub/js/adapter/graphql.js',
            '/bundles/pimcoredatahub/js/configuration/graphql/configItem.js',
            '/bundles/pimcoredatahub/js/fieldConfigDialog.js',
            '/bundles/pimcoredatahub/js/Abstract.js',
            '/bundles/pimcoredatahub/js/mutationvalue/DefaultValue.js',
            '/bundles/pimcoredatahub/js/queryvalue/DefaultValue.js',
            '/bundles/pimcoredatahub/js/queryoperator/Alias.js',
            '/bundles/pimcoredatahub/js/queryoperator/Concatenator.js',
            '/bundles/pimcoredatahub/js/queryoperator/DateFormatter.js',
            '/bundles/pimcoredatahub/js/queryoperator/ElementCounter.js',
            '/bundles/pimcoredatahub/js/queryoperator/Text.js',
            '/bundles/pimcoredatahub/js/queryoperator/Merge.js',
            '/bundles/pimcoredatahub/js/queryoperator/Substring.js',
            '/bundles/pimcoredatahub/js/queryoperator/Thumbnail.js',
            '/bundles/pimcoredatahub/js/queryoperator/ThumbnailHtml.js',
            '/bundles/pimcoredatahub/js/queryoperator/TranslateValue.js',
            '/bundles/pimcoredatahub/js/queryoperator/Trimmer.js',
            '/bundles/pimcoredatahub/js/mutationoperator/mutationoperator.js',
            '/bundles/pimcoredatahub/js/mutationoperator/IfEmpty.js',
            '/bundles/pimcoredatahub/js/mutationoperator/LocaleSwitcher.js',
            '/bundles/pimcoredatahub/js/mutationoperator/LocaleCollector.js',
            '/bundles/pimcoredatahub/js/workspace/abstract.js',
            '/bundles/pimcoredatahub/js/workspace/document.js',
            '/bundles/pimcoredatahub/js/workspace/asset.js',
            '/bundles/pimcoredatahub/js/workspace/object.js',
        ];
    }

    /**
     * If the bundle has an installation routine, an installer is responsible of handling installation related tasks
     *
     */
    public function getInstaller(): ?InstallerInterface
    {
        return $this->container->get(Installer::class);
    }

    /**
     * @return int
     */
    public static function getNotAllowedPolicy()
    {
        return self::$notAllowedPolicy;
    }

    /**
     * @param mixed $notAllowedPolicy
     */
    public static function setNotAllowedPolicy($notAllowedPolicy): void
    {
        self::$notAllowedPolicy = $notAllowedPolicy;
    }
}
