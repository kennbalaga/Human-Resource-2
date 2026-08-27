<?php

namespace Tests\Feature;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use Tests\TestCase;

/**
 * Every route must point at a class that actually loads.
 *
 * PHP resolves a class the first time something asks for it, so a controller
 * that references a trait it never imported sits there perfectly quiet until a
 * real request reaches it — a full green suite proves only that nothing in the
 * suite touched that file. This walks the route table instead, which is the one
 * list that covers the surfaces users can reach whether or not anyone wrote a
 * test for them.
 */
class RouteIntegrityTest extends TestCase
{
    public function test_every_routed_controller_class_loads(): void
    {
        $failures = [];

        foreach (Route::getRoutes() as $route) {
            /** @var RoutingRoute $route */
            $controller = $route->getAction('controller');

            if (! is_string($controller) || $controller === '') {
                continue;
            }

            $class = str_contains($controller, '@') ? strstr($controller, '@', true) : $controller;

            if (! str_starts_with($class, 'App\\')) {
                continue;
            }

            try {
                // Reflection forces the autoloader to compile the class, which
                // is what surfaces a missing trait, interface, or parent.
                new ReflectionClass($class);
            } catch (\Throwable $exception) {
                $failures[$class] = $exception->getMessage();
            }
        }

        $this->assertSame([], $failures, 'Routed controllers that fail to load: '.json_encode($failures, JSON_PRETTY_PRINT));
    }

    public function test_every_routed_controller_method_exists(): void
    {
        $failures = [];

        foreach (Route::getRoutes() as $route) {
            /** @var RoutingRoute $route */
            $controller = $route->getAction('controller');

            if (! is_string($controller) || ! str_contains($controller, '@') || ! str_starts_with($controller, 'App\\')) {
                continue;
            }

            [$class, $method] = explode('@', $controller, 2);

            if (class_exists($class) && ! method_exists($class, $method)) {
                $failures[] = $controller;
            }
        }

        $this->assertSame([], $failures);
    }
}
