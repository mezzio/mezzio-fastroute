<?php

declare(strict_types=1);

namespace MezzioTest\Router;

use Generator;
use Mezzio\Router\FastRouteRouter;
use Mezzio\Router\RouterInterface;
use Mezzio\Router\Test\AbstractImplicitMethodsIntegrationTest as RouterIntegrationTest;

class ImplicitMethodsIntegrationTest extends RouterIntegrationTest
{
    /**
     * @return FastRouteRouter
     */
    public function getRouter(): RouterInterface
    {
        return new FastRouteRouter();
    }

    /**
     * @return Generator<string, array{0: string, 1: array<string, mixed>, 2: string, 3: array<string, mixed>}>
     */
    public function implicitRoutesAndRequests(): Generator
    {
        // @codingStandardsIgnoreStart
        //                  route                     route options, request       params
        yield 'static'  => ['/api/v1/me',             [],            '/api/v1/me', []];
        yield 'dynamic' => ['/api/v{version:\d+}/me', [],            '/api/v3/me', ['version' => '3']];
        // @codingStandardsIgnoreEnd
    }
}
