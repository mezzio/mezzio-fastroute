<?php

declare(strict_types=1);

namespace MezzioTest\Router;

use Closure;
use Mezzio\Router\FastRouteRouter;
use Mezzio\Router\FastRouteRouterFactory;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Container\ContainerInterface;

class FastRouteRouterFactoryTest extends TestCase
{
    use ProphecyTrait;

    /** @var FastRouteRouterFactory */
    private $factory;

    /** @var ObjectProphecy<ContainerInterface> */
    private $container;

    protected function setUp(): void
    {
        $this->factory   = new FastRouteRouterFactory();
        $this->container = $this->prophesize(ContainerInterface::class);
    }

    public function testCreatesRouterWithEmptyConfig()
    {
        $this->container->has('config')->willReturn(false);

        $router = ($this->factory)($this->container->reveal());

        $this->assertInstanceOf(FastRouteRouter::class, $router);
        $cacheEnabled = Closure::bind(function () {
            return $this->cacheEnabled;
        }, $router, FastRouteRouter::class)();
        $this->assertFalse($cacheEnabled);

        $cacheFile = Closure::bind(function () {
            return $this->cacheFile;
        }, $router, FastRouteRouter::class)();
        $this->assertSame('data/cache/fastroute.php.cache', $cacheFile);
    }

    public function testCreatesRouterWithConfig()
    {
        $this->container->has('config')->willReturn(true);
        $this->container->get('config')->willReturn([
            'router' => [
                'fastroute' => [
                    FastRouteRouter::CONFIG_CACHE_ENABLED => true,
                    FastRouteRouter::CONFIG_CACHE_FILE    => '/foo/bar/file-cache',
                ],
            ],
        ]);

        $router = ($this->factory)($this->container->reveal());

        $this->assertInstanceOf(FastRouteRouter::class, $router);

        $cacheEnabled = Closure::bind(function () {
            return $this->cacheEnabled;
        }, $router, FastRouteRouter::class)();
        $this->assertTrue($cacheEnabled);

        $cacheFile = Closure::bind(function () {
            return $this->cacheFile;
        }, $router, FastRouteRouter::class)();
        $this->assertSame('/foo/bar/file-cache', $cacheFile);
    }
}
