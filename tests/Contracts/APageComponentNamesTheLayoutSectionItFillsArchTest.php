<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Livewire\Component;

// Livewire hands a routed component's HTML to the layout as $slot. Every layout
// in this repo is a @yield one, so a component that names no section renders
// into nothing at all — a 200 with an empty main, which no Livewire::test()
// can see because that harness never involves a layout. One route shipped that
// way and took close-to-tray with it.
it('gives every routed Livewire component a layout to render into', function (): void {
    /** @var Router $router */
    $router = app(Router::class);

    $offenders = [];
    foreach ($router->getRoutes() as $route) {
        /** @var RoutingRoute $route */
        $action = $route->getAction('uses');
        if (! is_string($action)) {
            continue;
        }

        $class = str_contains($action, '@') ? explode('@', $action)[0] : $action;
        if (! class_exists($class) || ! is_subclass_of($class, Component::class)) {
            continue;
        }

        $source = (string) file_get_contents((string) (new ReflectionClass($class))->getFileName());
        if (str_contains($source, "->extends('layouts.") || str_contains($source, '#[Layout(')) {
            continue;
        }

        $offenders[] = $route->uri().' → '.$class;
    }

    expect($offenders)->toBe([], implode("\n", [
        'These routes render a Livewire component into a layout that ignores its slot:',
        ...$offenders,
        '',
        "Call \$view->extends('layouts.app', ['title' => …]) in render(), or declare",
        '#[Layout] on the class, the way every other page component does.',
    ]));
});
