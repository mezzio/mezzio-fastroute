<?php

declare(strict_types=1);

namespace MezzioTest\Router;

use Closure;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Fig\Http\Message\RequestMethodInterface as RequestMethod;
use Laminas\Diactoros\ServerRequest;
use Mezzio\Router\Exception\InvalidCacheDirectoryException;
use Mezzio\Router\Exception\InvalidCacheException;
use Mezzio\Router\Exception\RuntimeException;
use Mezzio\Router\FastRouteRouter;
use Mezzio\Router\Route;
use Mezzio\Router\RouteResult;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Prophecy\Prophecy\ProphecyInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\MiddlewareInterface;

use function file_get_contents;
use function is_file;
use function unlink;

class FastRouteRouterTest extends TestCase
{
    use ProphecyTrait;

    private RouteCollector|ProphecyInterface $fastRouter;

    private Dispatcher|ProphecyInterface $dispatcher;

    /** @var callable */
    private $dispatchCallback;

    protected function setUp(): void
    {
        $this->fastRouter       = $this->prophesize(RouteCollector::class);
        $this->dispatcher       = $this->prophesize(Dispatcher::class);
        $this->dispatchCallback = fn() => $this->dispatcher->reveal();
    }

    private function getRouter(): FastRouteRouter
    {
        return new FastRouteRouter(
            $this->fastRouter->reveal(),
            $this->dispatchCallback
        );
    }

    private function getMiddleware(): MiddlewareInterface
    {
        return $this->prophesize(MiddlewareInterface::class)->reveal();
    }

    public function testWillLazyInstantiateAFastRouteCollectorIfNoneIsProvidedToConstructor(): void
    {
        $router         = new FastRouteRouter();
        $routeCollector = Closure::bind(fn() => $this->router, $router, FastRouteRouter::class)();

        $this->assertInstanceOf(RouteCollector::class, $routeCollector);
    }

    public function testAddingRouteAggregatesRoute(): void
    {
        $route  = new Route('/foo', $this->getMiddleware(), [RequestMethod::METHOD_GET]);
        $router = $this->getRouter();
        $router->addRoute($route);

        $routesToInject = Closure::bind(fn() => $this->routesToInject, $router, FastRouteRouter::class)();
        $this->assertContains($route, $routesToInject);
    }

    /**
     * @depends testAddingRouteAggregatesRoute
     */
    public function testMatchingInjectsRouteIntoFastRoute(): void
    {
        $route = new Route('/foo', $this->getMiddleware(), [RequestMethod::METHOD_GET]);
        $this->fastRouter->addRoute([RequestMethod::METHOD_GET], '/foo', '/foo')->shouldBeCalled();
        $this->fastRouter->getData()->shouldBeCalled();
        $this->dispatcher->dispatch(RequestMethod::METHOD_GET, '/foo')->willReturn([
            Dispatcher::NOT_FOUND,
        ]);

        $router = $this->getRouter();
        $router->addRoute($route);

        $uri = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/foo');

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->will(static fn(): object => $uri->reveal());
        $request->getMethod()->willReturn(RequestMethod::METHOD_GET);

        $router->match($request->reveal());
    }

    /**
     * @depends testAddingRouteAggregatesRoute
     */
    public function testGeneratingUriInjectsRouteIntoFastRoute(): void
    {
        $route = new Route('/foo', $this->getMiddleware(), [RequestMethod::METHOD_GET], 'foo');
        $this->fastRouter->addRoute([RequestMethod::METHOD_GET], '/foo', '/foo')->shouldBeCalled();

        $router = $this->getRouter();
        $router->addRoute($route);

        $this->assertEquals('/foo', $router->generateUri('foo'));
    }

    public function testIfRouteSpecifiesAnyHttpMethodFastRouteIsPassedHardCodedListOfMethods(): void
    {
        $route = new Route('/foo', $this->getMiddleware());
        $this->fastRouter
            ->addRoute(
                FastRouteRouter::HTTP_METHODS_STANDARD,
                '/foo',
                '/foo'
            )
            ->shouldBeCalled();

        $router = $this->getRouter();
        $router->addRoute($route);

        // routes are not injected until match or generateUri
        $router->generateUri($route->getName());
    }

    /**
     * @return (Route|RouteResult)[]
     * @psalm-return array{route: Route, result: RouteResult}
     */
    public function testMatchingRouteShouldReturnSuccessfulRouteResult(): array
    {
        $middleware = $this->getMiddleware();
        $route      = new Route('/foo', $middleware, [RequestMethod::METHOD_GET]);

        $uri = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/foo');

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->willReturn($uri);
        $request->getMethod()->willReturn(RequestMethod::METHOD_GET);

        $this->dispatcher->dispatch(RequestMethod::METHOD_GET, '/foo')->willReturn([
            Dispatcher::FOUND,
            '/foo',
            ['bar' => 'baz'],
        ]);

        $this->fastRouter->addRoute([RequestMethod::METHOD_GET], '/foo', '/foo')->shouldBeCalled();
        $this->fastRouter->getData()->shouldBeCalled();

        $router = $this->getRouter();
        $router->addRoute($route); // Must add, so we can determine middleware later
        $result = $router->match($request->reveal());
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertSame('/foo^GET', $result->getMatchedRouteName());
        $this->assertSame($middleware, $result->getMatchedRoute()->getMiddleware());
        $this->assertSame(['bar' => 'baz'], $result->getMatchedParams());

        return ['route' => $route, 'result' => $result];
    }

    /**
     * @depends testMatchingRouteShouldReturnSuccessfulRouteResult
     */
    public function testMatchedRouteResultContainsRoute(array $data): void
    {
        $route  = $data['route'];
        $result = $data['result'];
        $this->assertSame($route, $result->getMatchedRoute());
    }

    /**
     * @return iterable<string, string[]>
     */
    public function matchWithUrlEncodedSpecialCharsDataProvider(): iterable
    {
        return [
            'encoded-space'   => ['/foo/{id:.+}', '/foo/b%20ar', 'b ar'],
            'encoded-slash'   => ['/foo/{id:.+}', '/foo/b%2Fr', 'b/r'],
            'encoded-unicode' => ['/foo/{id:.+}', '/foo/bar-%E6%B8%AC%E8%A9%A6', 'bar-測試'],
            'encoded-regex'   => ['/foo/{id:bär}', '/foo/b%C3%A4r', 'bär'],
            'unencoded-regex' => ['/foo/{id:bär}', '/foo/bär', 'bär'],
        ];
    }

    /**
     * @see https://github.com/zendframework/zend-expressive-fastroute/pull/59
     *
     * @dataProvider matchWithUrlEncodedSpecialCharsDataProvider
     *
     * @param string $routePath
     * @param string $requestPath
     * @param string $expectedId
     */
    public function testMatchWithUrlEncodedSpecialChars($routePath, $requestPath, $expectedId): void
    {
        $request = $this->createServerRequestProphecy($requestPath, RequestMethod::METHOD_GET);

        $route = new Route($routePath, $this->getMiddleware(), [RequestMethod::METHOD_GET], 'foo');

        $router = new FastRouteRouter();
        $router->addRoute($route);

        $routeResult = $router->match($request->reveal());

        $this->assertTrue($routeResult->isSuccess());
        $this->assertSame('foo', $routeResult->getMatchedRouteName());
        $this->assertSame(
            ['id' => $expectedId],
            $routeResult->getMatchedParams()
        );
    }

    /**
     * @return iterable<string, string[]>
     */
    public function idemPotentMethods(): iterable
    {
        return [
            RequestMethod::METHOD_GET  => [RequestMethod::METHOD_GET],
            RequestMethod::METHOD_HEAD => [RequestMethod::METHOD_HEAD],
        ];
    }

    /**
     * @dataProvider idemPotentMethods
     */
    public function testRouteNotSpecifyingOptionsImpliesOptionsIsSupportedAndMatchesWhenGetOrHeadIsAllowed(
        string $method
    ): void {
        $route = new Route('/foo', $this->getMiddleware(), [RequestMethod::METHOD_POST, $method]);

        $uri = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/foo');

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->willReturn($uri);
        $request->getMethod()->willReturn(RequestMethod::METHOD_OPTIONS);

        // This test needs to determine what the default dispatcher does with
        // OPTIONS requests when the route does not support them. As a result,
        // it does not mock the router or dispatcher.
        $router = new FastRouteRouter();
        $router->addRoute($route); // Must add, so we can determine middleware later
        $result = $router->match($request->reveal());
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertFalse($result->isSuccess());
        $this->assertFalse($result->getMatchedRoute());
        $this->assertSame([RequestMethod::METHOD_POST, $method], $result->getAllowedMethods());
    }

    public function testRouteNotSpecifyingOptionsGetOrHeadMatchesOptions(): void
    {
        $route = new Route('/foo', $this->getMiddleware(), [RequestMethod::METHOD_POST]);

        $uri = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/foo');

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->willReturn($uri);
        $request->getMethod()->willReturn(RequestMethod::METHOD_OPTIONS);

        // This test needs to determine what the default dispatcher does with
        // OPTIONS requests when the route does not support them. As a result,
        // it does not mock the router or dispatcher.
        $router = new FastRouteRouter();
        $router->addRoute($route); // Must add, so we can determine middleware later
        $result = $router->match($request->reveal());
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertFalse($result->isSuccess());
        $this->assertSame([RequestMethod::METHOD_POST], $result->getAllowedMethods());
    }

    public function testRouteNotSpecifyingGetOrHeadDoesMatcheshHead(): void
    {
        $route = new Route('/foo', $this->getMiddleware(), [RequestMethod::METHOD_POST]);

        $uri = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/foo');

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->willReturn($uri);
        $request->getMethod()->willReturn(RequestMethod::METHOD_HEAD);

        // This test needs to determine what the default dispatcher does with
        // HEAD requests when the route does not support them. As a result,
        // it does not mock the router or dispatcher.
        $router = new FastRouteRouter();
        $router->addRoute($route); // Must add, so we can determine middleware later
        $result = $router->match($request->reveal());
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertFalse($result->isSuccess());
        $this->assertSame([RequestMethod::METHOD_POST], $result->getAllowedMethods());
    }

    /**
     * With GET provided explicitly, FastRoute will match a HEAD request.
     */
    public function testRouteSpecifyingGetDoesNotMatchHead(): void
    {
        $route = new Route('/foo', $this->getMiddleware(), [RequestMethod::METHOD_GET]);

        $uri = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/foo');

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->willReturn($uri);
        $request->getMethod()->willReturn(RequestMethod::METHOD_HEAD);

        // This test needs to determine what the default dispatcher does with
        // HEAD requests when the route does not support them. As a result,
        // it does not mock the router or dispatcher.
        $router = new FastRouteRouter();
        $router->addRoute($route); // Must add, so we can determine middleware later
        $result = $router->match($request->reveal());
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertFalse($result->isSuccess());
    }

    public function testMatchFailureDueToHttpMethodReturnsRouteResultWithAllowedMethods(): void
    {
        $route = new Route('/foo', $this->getMiddleware(), [RequestMethod::METHOD_POST]);

        $uri = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/foo');

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->willReturn($uri);
        $request->getMethod()->willReturn(RequestMethod::METHOD_GET);

        $this->dispatcher->dispatch(RequestMethod::METHOD_GET, '/foo')->willReturn([
            Dispatcher::METHOD_NOT_ALLOWED,
            [RequestMethod::METHOD_POST],
        ]);

        $this->fastRouter->addRoute([RequestMethod::METHOD_POST], '/foo', '/foo')->shouldBeCalled();
        $this->fastRouter->getData()->shouldBeCalled();

        $router = $this->getRouter();
        $router->addRoute($route); // Must add, so we can determine middleware later
        $result = $router->match($request->reveal());
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->isMethodFailure());
        $this->assertSame([RequestMethod::METHOD_POST], $result->getAllowedMethods());
    }

    public function testMatchFailureNotDueToHttpMethodReturnsGenericRouteFailureResult(): void
    {
        $route = new Route('/foo', $this->getMiddleware(), [RequestMethod::METHOD_GET]);

        $uri = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/bar');

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->willReturn($uri);
        $request->getMethod()->willReturn(RequestMethod::METHOD_GET);

        $this->dispatcher->dispatch(RequestMethod::METHOD_GET, '/bar')->willReturn([
            Dispatcher::NOT_FOUND,
        ]);

        $this->fastRouter->addRoute([RequestMethod::METHOD_GET], '/foo', '/foo')->shouldBeCalled();
        $this->fastRouter->getData()->shouldBeCalled();

        $router = $this->getRouter();
        $router->addRoute($route); // Must add, so we can determine middleware later
        $result = $router->match($request->reveal());
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isMethodFailure());
        $this->assertSame(Route::HTTP_METHOD_ANY, $result->getAllowedMethods());
    }

    /**
     * @return iterable<string, array{ 0:Route[], 1:string, 2:mixed[]}>
     */
    public function generatedUriProvider(): iterable
    {
        // @codingStandardsIgnoreStart
        $routes = [
            new Route('/foo', $this->getMiddleware(), [RequestMethod::METHOD_POST], 'foo-create'),
            new Route('/foo', $this->getMiddleware(), [RequestMethod::METHOD_GET], 'foo-list'),
            new Route('/foo/{id:\d+}', $this->getMiddleware(), [RequestMethod::METHOD_GET], 'foo'),
            new Route('/bar/{baz}', $this->getMiddleware(), Route::HTTP_METHOD_ANY, 'bar'),
            new Route('/index[/{page:\d+}]', $this->getMiddleware(), [RequestMethod::METHOD_GET], 'index'),
            new Route('/extra[/{page:\d+}[/optional-{extra:\w+}]]', $this->getMiddleware(), [RequestMethod::METHOD_GET], 'extra'),
            new Route('/page[/{page:\d+}/{locale:[a-z]{2}}[/optional-{extra:\w+}]]', $this->getMiddleware(), [RequestMethod::METHOD_GET], 'limit'),
            new Route('/api/{res:[a-z]+}[/{resId:\d+}[/{rel:[a-z]+}[/{relId:\d+}]]]', $this->getMiddleware(), [RequestMethod::METHOD_GET], 'api'),
            new Route('/optional-regex[/{optional:prefix-[a-z]+}]', $this->getMiddleware(), [RequestMethod::METHOD_GET], 'optional-regex'),
        ];

        return [
            // Test case                 routes   expected URI                   generateUri arguments
            'foo-create'             => [$routes, '/foo',                        ['foo-create']],
            'foo-list'               => [$routes, '/foo',                        ['foo-list']],
            'foo'                    => [$routes, '/foo/42',                     ['foo', ['id' => 42]]],
            'bar'                    => [$routes, '/bar/BAZ',                    ['bar', ['baz' => 'BAZ']]],
            'index'                  => [$routes, '/index',                      ['index']],
            'index-page'             => [$routes, '/index/42',                   ['index', ['page' => 42]]],
            'extra-42'               => [$routes, '/extra/42',                   ['extra', ['page' => 42]]],
            'extra-optional-segment' => [$routes, '/extra/42/optional-segment',  ['extra', ['page' => 42, 'extra' => 'segment']]],
            'limit'                  => [$routes, '/page/2/en/optional-segment', ['limit', ['locale' => 'en', 'page' => 2, 'extra' => 'segment']]],
            'api-optional-regex'     => [$routes, '/api/foo',                    ['api', ['res' => 'foo']]],
            'api-resource-id'        => [$routes, '/api/foo/1',                  ['api', ['res' => 'foo', 'resId' => 1]]],
            'api-relation'           => [$routes, '/api/foo/1/bar',              ['api', ['res' => 'foo', 'resId' => 1, 'rel' => 'bar']]],
            'api-relation-id'        => [$routes, '/api/foo/1/bar/2',            ['api', ['res' => 'foo', 'resId' => 1, 'rel' => 'bar', 'relId' => 2]]],
            'optional-regex'         => [$routes, '/optional-regex',             ['optional-regex']],
        ];
        // @codingStandardsIgnoreEnd
    }

    /**
     * @group zendframework/zend-expressive#53
     * @group 8
     * @dataProvider generatedUriProvider
     * @param string $expected
     */
    public function testCanGenerateUriFromRoutes(array $routes, $expected, array $generateArgs): void
    {
        $router = new FastRouteRouter();
        foreach ($routes as $route) {
            $router->addRoute($route);
        }

        $uri = $router->generateUri(...$generateArgs);
        $this->assertEquals($expected, $uri);
    }

    public function testOptionsPassedToGenerateUriOverrideThoseFromRoute(): void
    {
        $route = new Route(
            '/page[/{page:\d+}/{locale:[a-z]{2}}[/optional-{extra:\w+}]]',
            $this->getMiddleware(),
            [RequestMethod::METHOD_GET],
            'limit'
        );
        $route->setOptions([
            'defaults' => [
                'page'   => 1,
                'locale' => 'en',
                'extra'  => 'tag',
            ],
        ]);

        $router = new FastRouteRouter();
        $router->addRoute($route);

        $uri = $router->generateUri('limit', [], [
            'defaults' => [
                'page'   => 5,
                'locale' => 'de',
                'extra'  => 'sort',
            ],
        ]);
        $this->assertEquals('/page/5/de/optional-sort', $uri);
    }

    public function testReturnedRouteResultShouldContainRouteName(): void
    {
        $route = new Route('/foo', $this->getMiddleware(), [RequestMethod::METHOD_GET], 'foo-route');

        $uri = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/foo');

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->willReturn($uri);
        $request->getMethod()->willReturn(RequestMethod::METHOD_GET);

        $this->dispatcher->dispatch(RequestMethod::METHOD_GET, '/foo')->willReturn([
            Dispatcher::FOUND,
            '/foo',
            ['bar' => 'baz'],
        ]);

        $this->fastRouter->addRoute([RequestMethod::METHOD_GET], '/foo', '/foo')->shouldBeCalled();
        $this->fastRouter->getData()->shouldBeCalled();

        $router = $this->getRouter();
        $router->addRoute($route); // Must add, so we can determine middleware later
        $result = $router->match($request->reveal());
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('foo-route', $result->getMatchedRouteName());
    }

    /**
     * @return iterable<int, array{0:string, 1:array<string, string>}>
     */
    public function uriGeneratorDataProvider(): iterable
    {
        return [
            // both param1 and params2 are missing => use route defaults
            ['/foo/abc/def', []],

            // param1 is passed to the uri generator => use it
            // param2 is missing => use route default
            ['/foo/123/def', ['param1' => '123']],

            // param1 is missing => use route default
            // param2 is passed to the uri generator => use it
            ['/foo/abc/456', ['param2' => '456']],

            // both param1 and param2 are passed to the uri generator
            ['/foo/123/456', ['param1' => '123', 'param2' => '456']],
        ];
    }

    /**
     * @dataProvider uriGeneratorDataProvider
     */
    public function testUriGenerationSubstitutionsWithDefaultOptions(string $expectedUri, array $params): void
    {
        $router = new FastRouteRouter();

        $route = new Route('/foo/{param1}/{param2}', $this->getMiddleware(), [RequestMethod::METHOD_GET], 'foo');
        $route->setOptions([
            'defaults' => [
                'param1' => 'abc',
                'param2' => 'def',
            ],
        ]);

        $router->addRoute($route);

        $this->assertEquals($expectedUri, $router->generateUri('foo', $params));
    }

    /**
     * @dataProvider uriGeneratorDataProvider
     */
    public function testUriGenerationSubstitutionsWithDefaultsAndOptionalParameters(
        string $expectedUri,
        array $params
    ): void {
        $router = new FastRouteRouter();

        $route = new Route('/foo/{param1}/{param2}', $this->getMiddleware(), [RequestMethod::METHOD_GET], 'foo');
        $route->setOptions([
            'defaults' => [
                'param1' => 'abc',
                'param2' => 'def',
            ],
        ]);

        $router->addRoute($route);

        $this->assertEquals($expectedUri, $router->generateUri('foo', $params));
    }

    /**
     * @return iterable<int, array{0:string, 1:array<string, string>}>
     */
    public function uriGeneratorWithPartialDefaultsDataProvider(): iterable
    {
        return [
            // required param1 is missing => use route default
            // optional param2 is missing and no route default => skip it
            ['/foo/abc', []],

            // required param1 is passed to the uri generator => use it
            // optional param2 is missing and no route default => skip it
            ['/foo/123', ['param1' => '123']],

            // required param1 is missing => use default
            // optional param2 is passed to the uri generator => use it
            ['/foo/abc/456', ['param2' => '456']],

            // both param1 and param2 are passed to the uri generator
            ['/foo/123/456', ['param1' => '123', 'param2' => '456']],
        ];
    }

    /**
     * @dataProvider uriGeneratorWithPartialDefaultsDataProvider
     */
    public function testUriGenerationSubstitutionsWithPartialDefaultsAndOptionalParameters(
        string $expectedUri,
        array $params
    ): void {
        $router = new FastRouteRouter();

        $route = new Route('/foo/{param1}[/{param2}]', $this->getMiddleware(), [RequestMethod::METHOD_GET], 'foo');
        $route->setOptions([
            'defaults' => [
                'param1' => 'abc',
            ],
        ]);

        $router->addRoute($route);

        $this->assertEquals($expectedUri, $router->generateUri('foo', $params));
    }

    public function createCachingRouter(array $config, Route $route): FastRouteRouter
    {
        $router = new FastRouteRouter(null, null, $config);
        $router->addRoute($route);

        return $router;
    }

    /**
     * @psalm-return ObjectProphecy<ServerRequestInterface>
     */
    public function createServerRequestProphecy(
        string $path,
        string $method = RequestMethod::METHOD_GET
    ): ObjectProphecy {
        $uri = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn($path);

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->will(static fn(): object => $uri->reveal());

        $request->getMethod()->willReturn($method);

        return $request;
    }

    public function testFastRouteCache(): void
    {
        $cacheFile = __DIR__ . '/fastroute.cache';

        $config = [
            FastRouteRouter::CONFIG_CACHE_ENABLED => true,
            FastRouteRouter::CONFIG_CACHE_FILE    => $cacheFile,
        ];

        $request = $this->createServerRequestProphecy('/foo', RequestMethod::METHOD_GET);

        $middleware = $this->getMiddleware();
        $route      = new Route('/foo', $middleware, [RequestMethod::METHOD_GET], 'foo');

        $router1 = $this->createCachingRouter($config, $route);
        $router1->match($request->reveal());

        // cache file has been created with the specified path
        $this->assertTrue(is_file($cacheFile));

        $cache1 = file_get_contents($cacheFile);

        $router2 = $this->createCachingRouter($config, $route);

        $result = $router2->match($request->reveal());

        $this->assertTrue(is_file($cacheFile));

        // reload the cache file content to check for changes
        $cache2 = file_get_contents($cacheFile);

        $this->assertEquals($cache1, $cache2);

        // check that the routes defined and cached by $router1 are seen by
        // $router2
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertSame('foo', $result->getMatchedRouteName());
        $this->assertSame($middleware, $result->getMatchedRoute()->getMiddleware());

        unlink($cacheFile);
    }

    /**
     * Test for issue #30
     */
    public function testGenerateUriRaisesExceptionForMissingMandatoryParameters(): void
    {
        $router = new FastRouteRouter();
        $route  = new Route('/foo/{id}', $this->getMiddleware(), [RequestMethod::METHOD_GET], 'foo');
        $router->addRoute($route);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expects at least parameter values for');

        $router->generateUri('foo');
    }

    public function testGenerateUriRaisesExceptionForNotFoundRoute(): void
    {
        $router = new FastRouteRouter();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('route not found');
        $router->generateUri('foo');
    }

    public function testRouteResultContainsDefaultAndMatchedParams(): void
    {
        $route = new Route('/foo/{id}', $this->getMiddleware());
        $route->setOptions(['defaults' => ['bar' => 'baz']]);

        $router = new FastRouteRouter();
        $router->addRoute($route);

        $request = new ServerRequest(
            ['REQUEST_METHOD' => RequestMethod::METHOD_GET],
            [],
            '/foo/my-id',
            RequestMethod::METHOD_GET
        );

        $result = $router->match($request);

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isFailure());
        $this->assertSame(['bar' => 'baz', 'id' => 'my-id'], $result->getMatchedParams());
    }

    public function testMatchedRouteParamsOverrideDefaultParams(): void
    {
        $route = new Route('/foo/{bar}', $this->getMiddleware());
        $route->setOptions(['defaults' => ['bar' => 'baz']]);

        $router = new FastRouteRouter();
        $router->addRoute($route);

        $request = new ServerRequest(
            ['REQUEST_METHOD' => RequestMethod::METHOD_GET],
            [],
            '/foo/var',
            RequestMethod::METHOD_GET
        );

        $result = $router->match($request);

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isFailure());
        $this->assertSame(['bar' => 'var'], $result->getMatchedParams());
    }

    public function testMatchedCorrectRoute(): void
    {
        $route1 = new Route('/foo', $this->getMiddleware());
        $route2 = new Route('/bar', $this->getMiddleware());

        $router = new FastRouteRouter();
        $router->addRoute($route1);
        $router->addRoute($route2);

        $request = new ServerRequest(
            ['REQUEST_METHOD' => RequestMethod::METHOD_GET],
            [],
            '/bar',
            RequestMethod::METHOD_GET
        );

        $result = $router->match($request);

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isFailure());
        $this->assertSame($route2, $result->getMatchedRoute());
    }

    public function testExceptionWhenCacheDirectoryDoesNotExist(): void
    {
        vfsStream::setup('root');

        $router = new FastRouteRouter(null, null, [
            FastRouteRouter::CONFIG_CACHE_ENABLED => true,
            FastRouteRouter::CONFIG_CACHE_FILE    => vfsStream::url('root/dir/cache-file'),
        ]);

        $request = new ServerRequest(
            ['REQUEST_METHOD' => RequestMethod::METHOD_GET],
            [],
            '/foo',
            RequestMethod::METHOD_GET
        );

        $this->expectException(InvalidCacheDirectoryException::class);
        $this->expectExceptionMessage('does not exist');
        $router->match($request);
    }

    public function testExceptionWhenCacheDirectoryIsNotWritable(): void
    {
        $root = vfsStream::setup('root');
        vfsStream::newDirectory('dir', 0)->at($root);

        $router = new FastRouteRouter(null, null, [
            FastRouteRouter::CONFIG_CACHE_ENABLED => true,
            FastRouteRouter::CONFIG_CACHE_FILE    => vfsStream::url('root/dir/cache-file'),
        ]);

        $request = new ServerRequest(
            ['REQUEST_METHOD' => RequestMethod::METHOD_GET],
            [],
            '/foo',
            RequestMethod::METHOD_GET
        );

        $this->expectException(InvalidCacheDirectoryException::class);
        $this->expectExceptionMessage('is not writable');
        $router->match($request);
    }

    public function testExceptionWhenCacheFileExistsButIsNotWritable(): void
    {
        $root = vfsStream::setup('root');
        $file = vfsStream::newFile('cache-file', 0)->at($root);

        $router = new FastRouteRouter(null, null, [
            FastRouteRouter::CONFIG_CACHE_ENABLED => true,
            FastRouteRouter::CONFIG_CACHE_FILE    => $file->url(),
        ]);

        $request = new ServerRequest(
            ['REQUEST_METHOD' => RequestMethod::METHOD_GET],
            [],
            '/foo',
            RequestMethod::METHOD_GET
        );

        $this->expectException(InvalidCacheException::class);
        $this->expectExceptionMessage('is not writable');
        $router->match($request);
    }

    public function testExceptionWhenCacheFileExistsAndIsWritableButContainsNotAnArray(): void
    {
        $root = vfsStream::setup('root');
        $file = vfsStream::newFile('cache-file')->at($root);
        $file->setContent('<?php return "hello";');

        $this->expectException(InvalidCacheException::class);
        $this->expectExceptionMessage('MUST return an array');
        new FastRouteRouter(null, null, [
            FastRouteRouter::CONFIG_CACHE_ENABLED => true,
            FastRouteRouter::CONFIG_CACHE_FILE    => $file->url(),
        ]);
    }

    public function testGetAllAllowedMethods(): void
    {
        $route1 = new Route('/foo', $this->getMiddleware());
        $route2 = new Route('/bar', $this->getMiddleware(), [RequestMethod::METHOD_GET, RequestMethod::METHOD_POST]);
        $route3 = new Route('/bar', $this->getMiddleware(), [RequestMethod::METHOD_DELETE]);

        $router = new FastRouteRouter();
        $router->addRoute($route1);
        $router->addRoute($route2);
        $router->addRoute($route3);

        $request = new ServerRequest(
            ['REQUEST_METHOD' => RequestMethod::METHOD_HEAD],
            [],
            '/bar',
            RequestMethod::METHOD_HEAD
        );

        $result = $router->match($request);

        $this->assertFalse($result->isSuccess());
        $this->assertTrue($result->isFailure());
        $this->assertSame(
            [RequestMethod::METHOD_GET, RequestMethod::METHOD_POST, RequestMethod::METHOD_DELETE],
            $result->getAllowedMethods()
        );
    }

    public function testCustomDispatcherCallback(): void
    {
        $route1     = new Route('/foo', $this->getMiddleware());
        $dispatcher = $this->prophesize(Dispatcher::class);
        $dispatcher
            ->dispatch(RequestMethod::METHOD_GET, '/foo')
            ->shouldBeCalled()
            ->willReturn([
                Dispatcher::FOUND,
                '/foo',
                [],
            ]);

        $router = new FastRouteRouter(null, [$dispatcher, 'reveal']);
        $router->addRoute($route1);

        $request = new ServerRequest([], [], '/foo');
        $result  = $router->match($request);

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isFailure());
    }
}
