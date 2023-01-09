<?php

declare(strict_types=1);

namespace MezzioTest\Router\FastRouteRouter;

use Mezzio\Router\FastRouteRouter;
use Mezzio\Router\FastRouteRouter\ConfigProvider;
use Mezzio\Router\RouterInterface;
use PHPUnit\Framework\TestCase;

class ConfigProviderTest extends TestCase
{
    private ConfigProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new ConfigProvider();
    }

    public function testInvocationReturnsArray(): array
    {
        $config = ($this->provider)();
        /** @psalm-suppress RedundantCondition */
        self::assertIsArray($config);

        return $config;
    }

    /**
     * @depends testInvocationReturnsArray
     */
    public function testReturnedArrayContainsDependencies(array $config): void
    {
        self::assertArrayHasKey('dependencies', $config);
        self::assertIsArray($config['dependencies']);

        self::assertArrayHasKey('aliases', $config['dependencies']);
        self::assertIsArray($config['dependencies']['aliases']);
        self::assertArrayHasKey(RouterInterface::class, $config['dependencies']['aliases']);

        self::assertArrayHasKey('factories', $config['dependencies']);
        self::assertIsArray($config['dependencies']['factories']);
        self::assertArrayHasKey(FastRouteRouter::class, $config['dependencies']['factories']);
    }
}
