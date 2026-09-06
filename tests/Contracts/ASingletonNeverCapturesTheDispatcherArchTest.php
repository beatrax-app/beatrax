<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;

// Event::fake() replaces the container's `events` BINDING. It cannot reach a
// dispatcher already injected into a live singleton, so a test that fakes an
// event a singleton dispatches watches the wrong object: every database
// assertion passes and only assertDispatched fails.
//
// The symptom is worse than a plain failure — it depends on whether anything
// resolved that singleton first, so the same file passes in a full suite run
// and fails alone. AcknowledgeAnomalyAlertTest did exactly that.

/**
 * The chain from a shared binding to a constructor that takes a Dispatcher, or
 * null when it never reaches one. Transitive, because a singleton holding a
 * transient that holds the dispatcher has captured it just the same.
 *
 * First-party constructor parameters only. A binding that reaches the
 * dispatcher through a framework or package class is a chain this walk does not
 * follow, and the rule below says "a first-party chain" rather than "a chain"
 * because of it.
 *
 * @param  array<string, true>  $seen
 * @return list<string>|null
 */
function dispatcherChain(string $class, array $seen = []): ?array
{
    // A name no autoloader answers to carries no constructor to read, and a
    // class already on this chain is a cycle rather than a second capture.
    if (isset($seen[$class]) || ! class_exists($class)) {
        return null;
    }

    $seen[$class] = true;
    $constructor = (new ReflectionClass($class))->getConstructor();

    if ($constructor === null) {
        return null;
    }

    foreach ($constructor->getParameters() as $parameter) {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            continue;
        }

        $name = $type->getName();

        if (is_a($name, Dispatcher::class, true)) {
            return [$class];
        }

        if (! str_starts_with($name, 'Modules\\') && ! str_starts_with($name, 'App\\')) {
            continue;
        }

        $deeper = dispatcherChain($name, $seen);

        if ($deeper !== null) {
            return array_merge([$class], $deeper);
        }
    }

    return null;
}

/** @return list<string> */
function appOwnedSharedBindings(): array
{
    $app = app();
    /** @var array<string, array{shared?: bool}> $bindings */
    $bindings = (new ReflectionObject($app))->getProperty('bindings')->getValue($app);

    $shared = [];

    foreach (array_keys($bindings) as $abstract) {
        if (! is_string($abstract) || ! class_exists($abstract)) {
            continue;
        }

        if (empty($bindings[$abstract]['shared'])) {
            continue;
        }

        if (str_starts_with($abstract, 'Modules\\') || str_starts_with($abstract, 'App\\')) {
            $shared[] = $abstract;
        }
    }

    return $shared;
}

it('leaves no singleton holding a dispatcher through a first-party chain a fake would have to replace', function (): void {
    $shared = appOwnedSharedBindings();

    // A container with nothing registered would pass every assertion below it.
    expect(count($shared))->toBeGreaterThan(20, 'no app-owned singletons were found — the walk read an empty container');

    $offenders = [];

    foreach ($shared as $abstract) {
        $chain = dispatcherChain($abstract);

        if ($chain === null) {
            continue;
        }

        $short = array_map(static fn (string $c): string => substr($c, (int) strrpos($c, '\\') + 1), $chain);
        $offenders[] = implode(' -> ', $short).' takes a Dispatcher';
    }

    expect($offenders)->toBe([], implode("\n  ", [
        'These are registered as singletons and hold a dispatcher, so Event::fake()',
        'cannot reach them. Either stop registering the class as a singleton — a',
        'stateless one gains nothing from it — or, when the instance keeps a',
        'per-boot cache, take a Container and read the dispatcher per dispatch:',
        ...$offenders,
    ]));
});

it('sees a singleton that holds one, so the walk is not simply finding nothing', function (): void {
    // The guard above is only worth its runtime if it fails on the shape it
    // describes. This plants that shape and checks the walk reports it.
    $planted = new class(app(Dispatcher::class))
    {
        public function __construct(private Dispatcher $events) {}
    };

    expect(dispatcherChain($planted::class))->not->toBeNull(
        'The reader did not see a constructor taking a Dispatcher, so the sweep above is finding nothing rather '
        .'than finding the container clean.'
    );

    expect(dispatcherChain(stdClass::class))->toBeNull(
        'The reader reported a chain out of a class with no constructor at all, so every binding above would be '
        .'reported as capturing the dispatcher.'
    );
});
