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
use Pimcore\Extension\Bundle\AbstractPimcoreBundle;
use Pimcore\Extension\Bundle\Installer\InstallerInterface;
use Pimcore\Extension\Bundle\PimcoreBundleAdminClassicInterface;
use Pimcore\Extension\Bundle\Traits\BundleAdminClassicTrait;
use Pimcore\Extension\Bundle\Traits\PackageVersionTrait;
use Pimcore\HttpKernel\Bundle\DependentBundleInterface;
use Pimcore\HttpKernel\BundleCollection\BundleCollection;
use Pimcore\Logger;
use Symfony\Component\DependencyInjection\ContainerBuilder;

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
     * Once-per-process informational logging for the operation-classification
     * config tree. Two emissions:
     *
     *   - INFO: surfaces that in_progress_queries is in use so operators can plan
     *     migration to the richer `operations` shape. The list remains a permanent
     *     BC alias; this is not a deprecation warning.
     *   - WARNING: surfaces operationNames declared in both lists. The explicit
     *     `operations` entry wins; the warning enumerates the conflicting names so
     *     an operator can decide whether the duplication was intentional.
     *
     * Conflict detection happens in the Configuration validator (where both lists
     * are available before the fold) and is stashed in the sentinel key
     * `_in_progress_operations_conflicts`. boot() reads the sentinel and emits the
     * warning; Symfony Config validator closures run at container compile time and
     * cannot emit log lines reliably (Pimcore\Logger may not be wired yet).
     */
    public function boot(): void
    {
        parent::boot();

        if (!$this->container || !$this->container->hasParameter('pimcore_data_hub')) {
            return;
        }
        $cfg = $this->container->getParameter('pimcore_data_hub');
        if (!is_array($cfg)) {
            Logger::warning('pimcore_data_hub.boot_log_skipped: parameter is not an array');

            return;
        }
        $graphql = $cfg['graphql'] ?? [];
        if (!is_array($graphql)) {
            Logger::warning('pimcore_data_hub.boot_log_skipped: graphql key is not an array');

            return;
        }
        $inProgress = $graphql['in_progress_queries'] ?? [];
        $operations = $graphql['operations'] ?? [];
        if (!is_array($inProgress) || !is_array($operations)) {
            Logger::warning('pimcore_data_hub.boot_log_skipped: in_progress_queries or operations is not an array');

            return;
        }
        $inProgressNames = array_values(array_filter($inProgress, static fn ($v) => is_string($v) && $v !== ''));
        if ($inProgressNames === []) {
            return;
        }
        Logger::info(sprintf(
            'pimcore_data_hub.in_progress_queries_deprecated: %d entries (%s) fold into operations as { tier: herd_guarded, granularity: list }; consider migrating to the explicit operations config tree',
            count($inProgressNames),
            implode(', ', $inProgressNames)
        ));

        $conflicts = $graphql['_in_progress_operations_conflicts'] ?? [];
        if (is_array($conflicts) && $conflicts !== []) {
            Logger::warning(sprintf(
                'pimcore_data_hub.operations_in_progress_conflict: operationNames declared in both in_progress_queries and operations — explicit operations entry wins: %s',
                implode(', ', $conflicts)
            ));
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
