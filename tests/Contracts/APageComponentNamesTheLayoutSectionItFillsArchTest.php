<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Livewire\Component;
use Modules\Core\Public\Support\PatternScan;

/**
 * Whether a component's own source names a layout to render into. The prose is
 * dropped before the read: a page that only MENTIONS the idiom in a comment
 * renders into nothing all the same.
 */
function aPageComponentNamesItsLayout(string $source): bool
{
    $code = PatternScan::replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

    return str_contains($code, "->extends('layouts.") || str_contains($code, '#[Layout(');
}

// Livewire hands a routed component's HTML to the layout as $slot. Every layout
// in this repo is a @yield one, so a component that names no section renders
// into nothing at all — a 200 with an empty main, which no Livewire::test()
// can see because that harness never involves a layout. One route shipped that
// way and took close-to-tray with it.
it('gives every routed Livewire component a layout to render into', function (): void {
    /** @var Router $router */
    $router = app(Router::class);

    $offenders = [];
    $considered = 0;

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

        $considered++;

        if (aPageComponentNamesItsLayout((string) file_get_contents((string) (new ReflectionClass($class))->getFileName()))) {
            continue;
        }

        $offenders[] = $route->uri().' → '.$class;
    }

    // Fifty-six routes register a component today. A router handing back none
    // of them reports every page as covered from a walk that read nothing.
    expect($considered)->toBeGreaterThan(
        20,
        'Only '.$considered.' routed Livewire components were read, so this rule checked next to nothing.',
    );

    expect($offenders)->toBe([], implode("\n", [
        'These routes render a Livewire component into a layout that ignores its slot:',
        ...$offenders,
        '',
        "Call \$view->extends('layouts.app', ['title' => …]) in render(), or declare",
        '#[Layout] on the class, the way every other page component does.',
    ]));
});

it('reads a component that names no layout, and is not fooled by one that only mentions it', function (): void {
    $rendered = <<<'PHP'
        <?php
        final class Rendered
        {
            public function render(): mixed
            {
                return view('x')->extends('layouts.app', ['title' => 'x']);
            }
        }
        PHP;

    $attributed = "<?php\n#[Layout('layouts.app')]\nfinal class Attributed {}\n";

    $mentioned = <<<'PHP'
        <?php
        // This used to call $view->extends('layouts.app', [...]) and no longer does.
        final class Mentioned {}
        PHP;

    expect(aPageComponentNamesItsLayout($rendered))->toBeTrue('the render() spelling is the one fifty-two page components use');
    expect(aPageComponentNamesItsLayout($attributed))->toBeTrue('the attribute spelling is the other half of the rule');
    expect(aPageComponentNamesItsLayout($mentioned))->toBeFalse('a comment naming the idiom still renders into nothing at all');
});
