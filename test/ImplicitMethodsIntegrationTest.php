<?php

/**
 * @see       https://github.com/mezzio/mezzio-fastroute for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-fastroute/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-fastroute/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace MezzioTest\Router;

use Mezzio\Router\FastRouteRouter;
use Mezzio\Router\RouterInterface;
use Mezzio\Router\Test\ImplicitMethodsIntegrationTest as RouterIntegrationTest;

class ImplicitMethodsIntegrationTest extends RouterIntegrationTest
{
    public function getRouter() : RouterInterface
    {
        return new FastRouteRouter();
    }
}
