<?php

declare(strict_types=1);

namespace MezzioTest\Router;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Mezzio\Router\Exception\RuntimeException;
use Mezzio\Router\FastRouteRouter;
use Mezzio\Router\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\MiddlewareInterface;
use Throwable;

class UriGeneratorTest extends TestCase
{
    private FastRouteRouter $router;

    /**
     * Test routes taken from https://github.com/nikic/FastRoute/blob/master/test/RouteParser/StdTest.php
     *
     * @return array<string, string>
     */
    public static function provideRoutes(): array
    {
        return [
            'test_param_regex'       => '/test/{param:\d+}',
            'test_param_regex_limit' => '/test/{ param : \d{1,9} }',
            'test_optional'          => '/test[opt]',
            'test_optional_param'    => '/test[/{param}]',
            'param_and_opt'          => '/{param}[opt]',
            'test_double_opt'        => '/test[/{param}[/{id:[0-9]+}]]',
            'empty'                  => '',
            'optional_text'          => '[test]',
            'root_and_text'          => '/{foo-bar}',
            'root_and_regex'         => '/{_foo:.*}',
        ];
    }

    /**
     * @psalm-return array<int, array{0:string, 1:array<string, mixed>, 2:string}>
     */
    public static function provideRouteTests(): array
    {
        return [
            // path // substitutions[] // expected
            ['/test', [], '/test'],
            ['/test/{param}', ['param' => 'foo'], '/test/foo'],
            ['/te{ param }st', ['param' => 'foo'], '/tefoost'],
            [
                '/test/{param1}/test2/{param2}',
                ['param1' => 'foo', 'param2' => 'bar'],
                '/test/foo/test2/bar',
            ],
            ['/test/{param:\d+}', ['param' => 1], '/test/1'],
            //['/test/{param:\d+}', ['param' => 'foo'], 'exception', null],
            ['/test/{ param : \d{1,9} }', ['param' => 1], '/test/1'],
            ['/test/{ param : \d{1,9} }', ['param' => 123_456_789], '/test/123456789'],
            ['/test/{ param : \d{1,9} }', ['param' => 0], '/test/0'],
            ['/test[opt]', [], '/testopt'],
            ['/test[/{param}]', [], '/test'],
            ['/test[/{param}]', ['param' => 'foo'], '/test/foo'],
            ['/{param}[opt]', ['param' => 'foo'], '/fooopt'],
            ['/test[/{param}[/{id:[0-9]+}]]', [], '/test'],
            ['/test[/{param}[/{id:[0-9]+}]]', ['param' => 'foo'], '/test/foo'],
            ['/test[/{param}[/{id:[0-9]+}]]', ['param' => 'foo', 'id' => 1], '/test/foo/1'],
            ['/test[/{param}[/{id:[0-9]+}]]', ['id' => 1], '/test'],
            ['', [], ''],
            ['[test]', [], 'test'],
            ['/{foo-bar}', ['foo-bar' => 'bar'], '/bar'],
            ['/{_foo:.*}', ['_foo' => 'bar'], '/bar'],
        ];
    }

    /**
     * @psalm-return iterable<int, array{
     *     0:non-empty-string,
     *     1:array<string, mixed>,
     *     2:class-string<Throwable>,
     *     3:string
     * }>
     */
    public static function exceptionalRoutes(): iterable
    {
        return [
            [
                '/test/{param}',
                ['id' => 'foo'],
                RuntimeException::class,
                'expects at least parameter values for',
            ],
            [
                '/test/{ param : \d{1,9} }',
                ['param' => 1_234_567_890],
                RuntimeException::class,
                'Parameter value for [param] did not match the regex `\d{1,9}`',
            ],
            [
                '/test[/{param}[/{id:[0-9]+}]]',
                ['param' => 'foo', 'id' => 'foo'],
                RuntimeException::class,
                'Parameter value for [id] did not match the regex `[0-9]+`',
            ],
        ];
    }

    protected function setUp(): void
    {
        $fastRouter       = $this->createMock(RouteCollector::class);
        $dispatchCallback = fn(): Dispatcher => $this->createMock(Dispatcher::class);

        $this->router = new FastRouteRouter(
            $fastRouter,
            $dispatchCallback
        );
    }

    private function getMiddleware(): MiddlewareInterface
    {
        return $this->createMock(MiddlewareInterface::class);
    }

    #[DataProvider('provideRouteTests')]
    public function testRoutes(
        string $path,
        array $substitutions,
        string $expectedUrl
    ): void {
        $this->router->addRoute(new Route($path, $this->getMiddleware(), ['GET'], 'foo'));
        self::assertEquals($expectedUrl, $this->router->generateUri('foo', $substitutions));

        // Test with extra parameter
        $substitutions['extra'] = 'parameter';
        self::assertEquals($expectedUrl, $this->router->generateUri('foo', $substitutions));
    }

    /**
     * @param non-empty-string $path
     * @param class-string<Throwable> $expectedException
     */
    #[DataProvider('exceptionalRoutes')]
    public function testExceptionalRoutes(
        string $path,
        array $substitutions,
        string $expectedException,
        string $expectedMessage
    ): void {
        $this->router->addRoute(new Route($path, $this->getMiddleware(), ['GET'], 'route-name'));
        $this->expectException($expectedException);
        $this->expectExceptionMessage($expectedMessage);

        $this->router->generateUri('route-name', $substitutions);
    }
}
