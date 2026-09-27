<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\HttpException;
use App\Core\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
        $this->router->get('/', ['Home', 'root']);
        $this->router->group('/{lang:[a-z][a-z]}', ['MwA'], function (Router $r): void {
            $r->get('/', ['Home', 'index']);
            $r->group('/users', ['MwB'], function (Router $r): void {
                $r->get('/{id:\d+}', ['Users', 'show'])->middleware('MwC');
                $r->post('/{id:\d+}', ['Users', 'update']);
            });
            $r->get('/files/{name}', ['Files', 'show']);
        });
    }

    public function testMatchesStaticRoute(): void
    {
        [$route, $params] = $this->router->match('GET', '/');
        self::assertSame(['Home', 'root'], $route->handler);
        self::assertSame([], $params);
    }

    public function testTrailingNewlineDoesNotMatch(): void
    {
        $this->expectException(HttpException::class);
        $this->router->match('GET', "/fr/users/42\n");
    }

    public function testExtractsParametersAndAppliesConstraints(): void
    {
        [$route, $params] = $this->router->match('GET', '/fr/users/42');
        self::assertSame(['Users', 'show'], $route->handler);
        self::assertSame(['lang' => 'fr', 'id' => '42'], $params);

        $this->expectException(HttpException::class);
        $this->router->match('GET', '/fr/users/abc');
    }

    public function testGroupPrefixAndMiddlewareOrder(): void
    {
        [$route] = $this->router->match('GET', '/en/users/7');
        self::assertSame('/{lang:[a-z][a-z]}/users/{id:\d+}', $route->pattern);
        self::assertSame(['MwA', 'MwB', 'MwC'], $route->middlewares());

        [$home] = $this->router->match('GET', '/en');
        self::assertSame(['MwA'], $home->middlewares());
    }

    public function testTrailingSlashesAndDuplicateSlashesAreNormalized(): void
    {
        [$route] = $this->router->match('GET', '/fr/');
        self::assertSame(['Home', 'index'], $route->handler);
        [$route] = $this->router->match('GET', '/fr//users/3/');
        self::assertSame(['Users', 'show'], $route->handler);
    }

    public function testDefaultParameterDoesNotCrossSegments(): void
    {
        [, $params] = $this->router->match('GET', '/fr/files/report.pdf');
        self::assertSame('report.pdf', $params['name']);

        $this->assertHttpStatus(404, fn () => $this->router->match('GET', '/fr/files/a/b'));
    }

    public function testUnknownPathIs404(): void
    {
        $this->assertHttpStatus(404, fn () => $this->router->match('GET', '/nope/really'));
    }

    public function testWrongMethodIs405WithAllowHeader(): void
    {
        try {
            $this->router->match('DELETE', '/fr/users/1');
            self::fail('405 attendu');
        } catch (HttpException $e) {
            self::assertSame(405, $e->status());
            self::assertSame('GET, POST, HEAD', $e->headers()['Allow']);
        }
    }

    public function testHeadFallsBackToGet(): void
    {
        [$route] = $this->router->match('HEAD', '/fr/users/1');
        self::assertSame(['Users', 'show'], $route->handler);
    }

    public function testRegexMetacharactersInStaticSegmentsAreEscaped(): void
    {
        $router = new Router();
        $router->get('/a.b', ['X', 'y']);
        $this->assertHttpStatus(404, fn () => $router->match('GET', '/axb'));
        self::assertSame(['X', 'y'], $router->match('GET', '/a.b')[0]->handler);
    }

    private function assertHttpStatus(int $status, callable $fn): void
    {
        try {
            $fn();
            self::fail('HttpException ' . $status . ' attendue');
        } catch (HttpException $e) {
            self::assertSame($status, $e->status());
        }
    }
}
