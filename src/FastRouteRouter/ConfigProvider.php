<?php

/**
 * @see       https://github.com/mezzio/mezzio-fastroute for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-fastroute/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-fastroute/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace Mezzio\Router\FastRouteRouter;

use Mezzio\Router\FastRouteRouter;
use Mezzio\Router\FastRouteRouterFactory;
use Mezzio\Router\RouterInterface;

class ConfigProvider
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke() : array
    {
        return [
            'dependencies' => $this->getDependencies(),
        ];
    }

    /**
     * @return array<string, string[]>
     */
    public function getDependencies() : array
    {
        return [
            'aliases' => [
                RouterInterface::class => FastRouteRouter::class,

                // Legacy Zend Framework aliases
                \Zend\Expressive\Router\RouterInterface::class => RouterInterface::class,
                \Zend\Expressive\Router\FastRouteRouter\FastRouteRouter::class => FastRouteRouter::class,
            ],
            'factories' => [
                FastRouteRouter::class => FastRouteRouterFactory::class,
            ],
        ];
    }
}
