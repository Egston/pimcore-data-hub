<?php
declare(strict_types=1);
/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Enterprise License (PEL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PEL
 */

namespace Pimcore\Bundle\DataHubBundle\GraphQL\DataObjectQueryOperatorConfigGenerator;

use GraphQL\Type\Definition\Type;
use Pimcore\Bundle\DataHubBundle\GraphQL\DataObjectQueryFieldConfigGenerator\ImageGallery;
use Pimcore\Bundle\DataHubBundle\GraphQL\Resolver;

/**
 * Class ThumbnailHtml
 * @package Pimcore\Bundle\DataHubBundle\GraphQL\QueryOperatorConfigGenerator
 */
class ThumbnailHtml extends Base
{
    /**
     * @param $config
     * @param null $container
     * @return mixed
     */
    public function enrichConfig($config, $container = null)
    {
        $config['description'] = "A thumbnail HTML snippet which contains the entire image editable as generated by Pimcore";

        // If the resolver is an array, this should be configured as a listOf(strings) type instead of a single string type
        if ($config['resolve'][0] instanceof Resolver\Base) {
            /** @var Resolver\Base $cResolver */
            $cResolver = $config['resolve'][0];
            if ($cResolver->getResolverAttribute('dataType') === ImageGallery::TYPE) {
                $config['type'] = Type::listOf(Type::string());
                $config['description'] = "A list of thumbnail HTML snippets which contains the entire image editables of the gallery as generated by Pimcore";
            }
        }

        return parent::enrichConfig($config, $container);
    }
}
