<?php

declare(strict_types=1);

namespace Mezzio\Router;

use Psr\Container\ContainerInterface;

/**
 * Create and return an instance of FastRouteRouter.
 *
 * Configuration should look like the following:
 *
 * <code>
 * 'router' => [
 *     'fastroute' => [
 *         'cache_enabled' => true, // true|false
 *         'cache_file'   => '(/absolute/)path/to/cache/file', // optional
 *     ],
 * ]
 * </code>
 */
class FastRouteRouterFactory
{
    public function __invoke(ContainerInterface $container): FastRouteRouter
    {
        $config = $container->has('config')
            ? $container->get('config')
            : [];

        $config = $config['router']['fastroute'] ?? [];

        return new FastRouteRouter(null, null, $config);
    }
}
