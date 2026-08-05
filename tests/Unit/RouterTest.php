<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Router;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    private function router(): Router
    {
        $noop = fn () => null;

        return (new Router())
            ->get('/api/robots', $noop)
            ->post('/api/robots', $noop)
            ->patch('/api/robots/{id}/status', $noop)
            ->post('/api/schedules/{id}/complete', $noop);
    }

    public function testMatchesAStaticRoute(): void
    {
        $this->assertSame(200, $this->router()->match('GET', '/api/robots')['status']);
    }

    public function testExtractsPathParameters(): void
    {
        $route = $this->router()->match('PATCH', '/api/robots/42/status');

        $this->assertSame(200, $route['status']);
        $this->assertSame(['id' => '42'], $route['params']);
    }

    /** The old router 404'd anything it did not recognise, including wrong-method hits. */
    public function testReturns405WithAllowHeaderWhenOnlyTheMethodIsWrong(): void
    {
        $route = $this->router()->match('DELETE', '/api/robots');

        $this->assertSame(405, $route['status']);
        $this->assertSame(['GET', 'POST'], $route['allowed']);
    }

    public function testReturns404ForAnUnknownPath(): void
    {
        $this->assertSame(404, $this->router()->match('GET', '/api/nope')['status']);
    }

    /**
     * "/" and "/api" used to emit undefined-array-key warnings because the old
     * router indexed $uri[1] and $uri[2] without checking they existed.
     */
    public function testShortPathsResolveCleanly(): void
    {
        foreach (['/', '/api', '/api/'] as $path) {
            $this->assertSame(404, $this->router()->match('GET', $path)['status'], $path);
        }
    }

    public function testPlaceholderDoesNotSpanSlashes(): void
    {
        $this->assertSame(404, $this->router()->match('PATCH', '/api/robots/1/2/status')['status']);
    }

    public function testHandlerIsInvokedWithPositionalParameters(): void
    {
        $router = (new Router())->post('/api/schedules/{id}/complete', fn (string $id) => "done:{$id}");
        $route  = $router->match('POST', '/api/schedules/9/complete');

        $this->assertSame('done:9', ($route['handler'])(...array_values($route['params'])));
    }
}
