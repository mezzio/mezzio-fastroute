<?php

declare(strict_types=1);

namespace MezzioTest\Router;

use Closure;
use Mezzio\Router\FastRouteRouter;
use Mezzio\Router\FastRouteRouterFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class FastRouteRouterFactoryTest extends TestCase
{
    private FastRouteRouterFactory $factory;

    /** @var ContainerInterface&MockObject */
    private ContainerInterface $container;

    protected function setUp(): void
    {
        $this->factory   = new FastRouteRouterFactory();
        $this->container = $this->createMock(ContainerInterface::class);
    }

    public function testCreatesRouterWithEmptyConfig(): void
    {
        $this->container->expects(self::once())
            ->method('has')
            ->with('config')
            ->willReturn(false);

        $router = ($this->factory)($this->container);

        self::assertInstanceOf(FastRouteRouter::class, $router);
        $cacheEnabled = Closure::bind(fn() => $this->cacheEnabled, $router, FastRouteRouter::class)();
        self::assertFalse($cacheEnabled);

        $cacheFile = Closure::bind(fn() => $this->cacheFile, $router, FastRouteRouter::class)();
        self::assertSame('data/cache/fastroute.php.cache', $cacheFile);
    }

    public function testCreatesRouterWithConfig(): void
    {
        $this->container->expects(self::once())
            ->method('has')
            ->with('config')
            ->willReturn(true);
        $this->container->expects(self::once())
            ->method('get')
            ->with('config')->willReturn([
                'router' => [
                    'fastroute' => [
                        FastRouteRouter::CONFIG_CACHE_ENABLED => true,
                        FastRouteRouter::CONFIG_CACHE_FILE    => '/foo/bar/file-cache',
                    ],
                ],
            ]);

        $router = ($this->factory)($this->container);

        self::assertInstanceOf(FastRouteRouter::class, $router);

        $cacheEnabled = Closure::bind(fn() => $this->cacheEnabled, $router, FastRouteRouter::class)();
        self::assertTrue($cacheEnabled);

        $cacheFile = Closure::bind(fn() => $this->cacheFile, $router, FastRouteRouter::class)();
        self::assertSame('/foo/bar/file-cache', $cacheFile);
    }
}
