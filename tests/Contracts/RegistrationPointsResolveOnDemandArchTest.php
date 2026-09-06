<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Events\Dispatcher;

// The discriminator is the container's own answer, not a list of class names.
// A provider that writes `bind(SomeConcreteClass::class, ...)` has said, in a
// reviewable line, that a shared instance of that class is WRONG — each caller
// must get one built from the bindings and the configuration in force when the
// call happens. An interface binding says something else entirely (which
// implementation), which is why only concrete abstracts seed the scan; without
// that split `bind(CurrentUser::class, CurrentUserService::class)` would make
// every listener that reads the current user an offender.
//
// Rejected: seeding from a hand-written list of "the pairing/relay graph", which
// would have caught the four known instances and nothing else; and seeding from
// "has a setter", which reads RelayConfig correctly but misses DeviceIdentityLoader
// and OpLogWriter, two of the four.

/**
 * Concrete classes the container is told to build fresh on every resolve.
 *
 * @return array<string, true>
 */
function eagerGraphPerResolveBindings(): array
{
    $seeds = [];

    foreach (app()->getBindings() as $abstract => $binding) {
        if (($binding['shared'] ?? false) === true) {
            continue;
        }

        if (! is_string($abstract) || ! class_exists($abstract) || interface_exists($abstract)) {
            continue;
        }

        $seeds[$abstract] = true;
    }

    return $seeds;
}

// bind()/singleton() wrap a class-string concrete in a closure that closes over
// it, so the target survives as a static variable and an interface-typed
// constructor parameter can still be followed to what would actually be built.
function eagerGraphConcreteFor(string $abstract): string
{
    $abstract = app()->getAlias($abstract);
    $binding = app()->getBindings()[$abstract] ?? null;

    if ($binding === null) {
        return $abstract;
    }

    $concrete = $binding['concrete'] ?? null;

    if (is_string($concrete) && class_exists($concrete)) {
        return $concrete;
    }

    if ($concrete instanceof Closure) {
        $target = (new ReflectionFunction($concrete))->getStaticVariables()['concrete'] ?? null;

        if (is_string($target) && class_exists($target)) {
            return $target;
        }
    }

    return $abstract;
}

/**
 * The path from a constructor parameter down to a per-resolve class, or [] when
 * there is none. First-party classes only: the walk stops at the vendor
 * boundary, where nothing depends back on this application.
 *
 * @param  array<string, true>  $seeds
 * @param  array<string, true>  $seen
 * @return list<string>
 */
function eagerGraphChainTo(string $type, array $seeds, array $seen = []): array
{
    $concrete = eagerGraphConcreteFor($type);
    $head = $concrete === $type ? [$type] : [$type, $concrete];

    if (isset($seeds[$type]) || isset($seeds[$concrete])) {
        return $head;
    }

    if (isset($seen[$concrete]) || ! class_exists($concrete)) {
        return [];
    }

    if (! str_starts_with($concrete, 'Modules\\') && ! str_starts_with($concrete, 'App\\')) {
        return [];
    }

    $seen[$concrete] = true;
    $constructor = (new ReflectionClass($concrete))->getConstructor();

    if ($constructor === null) {
        return [];
    }

    foreach ($constructor->getParameters() as $parameter) {
        foreach (eagerGraphParameterTypes($parameter) as $dependency) {
            $chain = eagerGraphChainTo($dependency, $seeds, $seen);

            if ($chain !== []) {
                return [...$head, ...$chain];
            }
        }
    }

    return [];
}

/**
 * @return list<string>
 */
function eagerGraphParameterTypes(ReflectionParameter $parameter): array
{
    $type = $parameter->getType();
    $named = match (true) {
        $type instanceof ReflectionNamedType => [$type],
        $type instanceof ReflectionUnionType, $type instanceof ReflectionIntersectionType => $type->getTypes(),
        default => [],
    };

    $names = [];
    foreach ($named as $one) {
        if ($one instanceof ReflectionNamedType && ! $one->isBuiltin()) {
            $names[] = $one->getName();
        }
    }

    return $names;
}

/**
 * Every place this application registers a class to be constructed once and
 * kept: an Artisan command (built when the command list is assembled) and an
 * event listener (built on the first dispatch of its event, which can be far
 * earlier than the work it does). Both come from the framework's own
 * registries, so a module added tomorrow is in scope without a list edit.
 *
 * @return array<string, string>
 */
function eagerGraphRegistrationPoints(): array
{
    $points = [];

    /** @var ConsoleKernel $kernel */
    $kernel = app(ConsoleKernel::class);
    foreach ($kernel->all() as $name => $command) {
        $class = $command::class;
        if (str_starts_with($class, 'Modules\\') || str_starts_with($class, 'App\\')) {
            $points[$class] = "artisan {$name}";
        }
    }

    /** @var Dispatcher $events */
    $events = app(Dispatcher::class);
    foreach ($events->getRawListeners() as $event => $listeners) {
        foreach ((is_array($listeners) ? $listeners : [$listeners]) as $listener) {
            $class = match (true) {
                is_string($listener) => explode('@', $listener)[0],
                is_array($listener) && isset($listener[0]) && is_string($listener[0]) => $listener[0],
                default => null,
            };

            if ($class === null || ! class_exists($class)) {
                continue;
            }

            if (str_starts_with($class, 'Modules\\') || str_starts_with($class, 'App\\')) {
                $points[$class] = 'listener for '.(is_string($event) ? class_basename($event) : 'an event');
            }
        }
    }

    ksort($points);

    return $points;
}

/**
 * @return list<string>
 */
function eagerGraphFrozenInjections(): array
{
    $seeds = eagerGraphPerResolveBindings();
    $found = [];

    foreach (eagerGraphRegistrationPoints() as $point => $_) {
        $constructor = (new ReflectionClass($point))->getConstructor();

        if ($constructor === null) {
            continue;
        }

        foreach ($constructor->getParameters() as $parameter) {
            foreach (eagerGraphParameterTypes($parameter) as $type) {
                $chain = eagerGraphChainTo($type, $seeds);

                if ($chain !== []) {
                    $found[] = $point.' -> '.implode(' -> ', $chain);
                }
            }
        }
    }

    sort($found);

    return array_values(array_unique($found));
}

it('scans the registration points and the per-resolve bindings it claims to', function (): void {
    $points = eagerGraphRegistrationPoints();
    $seeds = eagerGraphPerResolveBindings();

    expect($points)->not->toBe([], 'The registration-point scan found nothing, so the rule below can only pass vacuously.');
    expect($seeds)->not->toBe([], 'The per-resolve binding scan found nothing, so the rule below can only pass vacuously.');

    // Short names throughout this test. Almost every class the rule was written
    // for lives in a neighbour's Internal\ namespace, and BoundaryArchTest bans
    // naming one of those from here outright rather than pinning it — an
    // inline fully-qualified reference is the one form its static-analysis half
    // cannot see. A basename is enough for what these assertions are for:
    // proving the scan still reaches the classes, not identifying them.
    $names = static fn (array $keys): array => array_values(array_map(
        static fn (string $fqcn): string => class_basename($fqcn),
        $keys,
    ));

    // The four shipped instances. Each is listed here so the rule cannot go
    // quiet by losing sight of the class it was written for.
    expect($names(array_keys($points)))->toContain(
        'SyncServeCommand',
        'HoldPairingCeremonyOpenOnUnlock',
        'SyncCaptureListener',
        'StartSyncListenerOnEnable',
    );

    // And the bindings that make them reachable. A provider that quietly turned
    // one of these into a singleton would disarm the rule without failing it.
    expect($names(array_keys($seeds)))->toContain(
        'DeviceIdentityLoader',
        'OpLogWriter',
        'PendingPairingCourier',
        'SyncWebSocketHandler',
    );

    // The half of the discriminator that keeps the rule usable. An interface
    // binding chooses an implementation and says nothing about lifetime, so it
    // must never seed: CurrentUser is bound exactly that way and is injected
    // into registration points on purpose, including the very listener this
    // rule was written for.
    expect($names(array_keys($seeds)))->not->toContain('CurrentUser');

    // A scan that lost its way to a handful of classes would still satisfy the
    // assertions above. This is the population the pinned list is measured
    // against, and it is most of the application's long-lived construction.
    expect(count($points))->toBeGreaterThan(50);

    // The four, as they now stand: each is reached by the scan and each comes
    // back clean, so the pinned list below is a list of other people's classes.
    // Matched on the head of the chain, not on equality — an entry is a whole
    // path, and an equality check here would pass whatever the rule found.
    $frozen = eagerGraphFrozenInjections();
    foreach (['SyncServeCommand', 'HoldPairingCeremonyOpenOnUnlock', 'SyncCaptureListener', 'StartSyncListenerOnEnable'] as $fixed) {
        $stillFrozen = array_values(array_filter(
            $frozen,
            static fn (string $entry): bool => class_basename(explode(' -> ', $entry)[0]) === $fixed,
        ));

        expect($stillFrozen)->toBe([], "{$fixed} was fixed by resolving on demand and has gone back to injecting: "
            .implode(', ', $stillFrozen));
    }
});

it('does not let a console command or an event listener freeze a per-resolve service graph', function (): void {
    // Each entry is one constructor parameter of one registration point, with
    // the path from it to the class the container builds fresh every time.
    $pinned = [
        // These seeders reach PeriodQuery, which is per-resolve because it reads
        // the per-REQUEST current user. A dev-only seeder command is one process
        // with one user context, so there is no second user for a frozen
        // instance to be wrong about. Three now reach it through DemoPeriodWindow,
        // the one window the demo grid and the demo rows are cut from.

        // FingerprintRederiveService is per-resolve by habit rather than by
        // need: its whole graph is FingerprintComposer (a singleton) and the
        // database manager, and neither reads anything a later action writes.
        'Modules\\Ledger\\Internal\\Console\\RederiveFingerprintsCommand -> Modules\\Ledger\\Internal\\Services\\FingerprintRederiveService',
    ];

    $actual = eagerGraphFrozenInjections();
    $added = array_values(array_diff($actual, $pinned));
    $gone = array_values(array_diff($pinned, $actual));

    expect($actual)->toBe($pinned, <<<'WHY'
        A console command or an event listener constructor-injects a service graph that reaches a
        class the container is told to build fresh on every resolve. The container obeys the
        constructor first: it builds that graph when the registration point is built — Artisan
        assembling its command list, or the first dispatch of an event — and the object is then
        held for the life of the process, together with every singleton its construction created.
        Configuration written afterwards is invisible to it. That is how a relay configured by
        scanning a pairing QR reached a courier that had frozen the previous transport, and how a
        daemon read an identity from the AppLockKeyService bound before the app was unlocked.

        Take a Closure factory instead, and call it when the work happens — the shape
        SyncServeCommand already uses for its handler — or inject the container and make() on demand,
        the shape SyncCaptureListener and HoldPairingCeremonyOpenOnUnlock use.

        This rule sees LESS than the hazard it is named for. It reads constructor parameter types
        only, so a graph reached through make() in a constructor body, a captured Closure, or a
        facade is invisible. It knows a class is late-configured only where a provider said so with
        a concrete-class bind(); a hazardous class nobody bound at all looks neutral, and is
        followed only as a path to one that was. It covers Artisan commands and class-name event
        listeners; a closure listener, a middleware, a queue worker, a Livewire component and a
        provider closure that captures a resolved object have the same hazard and no coverage here.
        And it cannot tell a freeze that matters from one that does not, which is why the entries
        below are pinned by hand rather than filtered by a heuristic.
        WHY
        ."\nNot pinned:\n  ".implode("\n  ", $added === [] ? ['-'] : $added)
        ."\nPinned but no longer found (delete the line — the fix is done):\n  "
        .implode("\n  ", $gone === [] ? ['-'] : $gone));
});
