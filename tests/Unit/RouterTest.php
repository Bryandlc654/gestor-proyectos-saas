<?php

namespace Tests\Unit;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testDispatchesByAction(): void
    {
        $router = (new Router())->on('ping', fn(Request $r) => ['success' => true, 'echo' => $r->string('msg')]);

        [$status, $payload] = $router->handle(new Request('POST', [], ['action' => 'ping', 'msg' => ' hola ']));

        $this->assertSame(200, $status);
        $this->assertSame(['success' => true, 'echo' => 'hola'], $payload);
    }

    public function testUnknownActionReturns400(): void
    {
        [$status, $payload] = (new Router())->handle(new Request('POST', [], ['action' => 'nada']));
        $this->assertSame(400, $status);
        $this->assertFalse($payload['success']);
    }

    public function testHttpExceptionBecomesJsonError(): void
    {
        $router = (new Router())->on('x', fn() => throw HttpException::forbidden('Prohibido'));
        [$status, $payload] = $router->handle(new Request('POST', [], ['action' => 'x']));

        $this->assertSame(403, $status);
        $this->assertSame(['success' => false, 'message' => 'Prohibido'], $payload);
    }

    public function testRequestHelpers(): void
    {
        $r = new Request('post', ['page' => '3'], ['id' => '42', 'empty' => '', 'prio' => 'alta', 'arr' => ['x']]);

        $this->assertSame('POST', $r->method());
        $this->assertSame(42, $r->int('id'));
        $this->assertSame(3, $r->int('page'));
        $this->assertSame(0, $r->int('missing'));
        $this->assertNull($r->nullableInt('empty'));
        $this->assertSame(42, $r->nullableInt('id'));
        $this->assertSame('alta', $r->enum('prio', ['baja', 'alta'], 'baja'));
        $this->assertSame('baja', $r->enum('prio', ['baja', 'media'], 'baja'));
        $this->assertSame('', $r->string('arr'), 'Un array no se trata como texto');
    }
}
