<?php

/**
 * @see       https://github.com/mezzio/mezzio-fastroute for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-fastroute/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-fastroute/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace MezzioTest\Router;

use Generator;
use Mezzio\Router\FastRouteRouter;
use Mezzio\Router\RouterInterface;
use Mezzio\Router\Test\ImplicitMethodsIntegrationTest as RouterIntegrationTest;

class ImplicitMethodsIntegrationTest extends RouterIntegrationTest
{
    public function getRouter() : RouterInterface
    {
        return new FastRouteRouter();
    }

    public function implicitRoutesAndRequests() : Generator
    {
        // @codingStandardsIgnoreStart
        //                  route                     route options, request       params
        yield 'static'  => ['/api/v1/me',             [],            '/api/v1/me', []];
        yield 'dynamic' => ['/api/v{version:\d+}/me', [],            '/api/v3/me', ['version' => '3']];
        // @codingStandardsIgnoreEnd
    }
}
