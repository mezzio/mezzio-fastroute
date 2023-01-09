<?php

declare(strict_types=1);

namespace Mezzio\Router;

use ArrayAccess;
use Psr\Container\ContainerInterface;

use function assert;
use function is_array;

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
 *
 * @psalm-import-type FastRouteConfig from FastRouteRouter
 */
class FastRouteRouterFactory
{
    public function __invoke(ContainerInterface $container): FastRouteRouter
    {
        $config = $container->has('config')
            ? $container->get('config')
            : [];

        assert(is_array($config) || $config instanceof ArrayAccess);
        $routerConfig = $config['router'] ?? [];
        assert(is_array($routerConfig) || $routerConfig instanceof ArrayAccess);
        $options = $routerConfig['fastroute'] ?? [];
        assert(is_array($options));
        /** @psalm-var FastRouteConfig $options */

        return new FastRouteRouter(null, null, $options);
    }
}
