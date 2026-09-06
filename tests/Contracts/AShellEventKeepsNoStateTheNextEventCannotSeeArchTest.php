<?php

declare(strict_types=1);

use Tests\Helpers\ShellEventGraph;

// The sibling guard beside this one asks what a shell event may TOUCH. This one
// asks what it may KEEP. The bundle serves the app through `php -S`, so every
// _native/api/events POST runs in a PHP process of its own and takes a freshly
// built container with it: a property is born at the start of the event and
// dies at the end of it, whatever the provider binds the holder as.
//
// Both classes that ignored that were held as singletons and documented as
// load-bearing. The crash watchdog counted three exits in five minutes on an
// array that started empty at every exit, so it never once escalated, and the
// window-focus flag was read as its constructed `true` by every notification
// the desktop ever decided not to show.

// The three holders whose mutable state this rule was written after. Named
// rather than counted: a count survives a graph that quietly stopped resolving
// one and picked up something else, and an unresolved class is skipped in
// silence below.
/** @return list<class-string> */
function shellStateAnchors(): array
{
    return [
        'Modules\\Desktop\\Internal\\Native\\WindowFocusState',
        'Modules\\Desktop\\Internal\\Listeners\\SurfaceWorkerCrashAlert',
        'Modules\\Desktop\\Internal\\Native\\ShellState',
    ];
}

/**
 * @return list<string>
 */
function shellStateOffences(): array
{
    $offences = [];

    foreach (ShellEventGraph::reach() as $class => $seed) {
        // A name the graph reached but the autoloader cannot resolve carries no
        // properties to read, so it is skipped rather than reported. The rule
        // below asserts the three anchors do resolve, because a walk that
        // resolved none of them reports the same clean tree a correct one does.
        if (ShellEventGraph::classFile($class) === null || ! class_exists($class)) {
            continue;
        }

        foreach ((new ReflectionClass($class))->getProperties() as $property) {
            // Only what this class declares. An inherited property belongs to
            // the parent's own contract -- a spatie/laravel-data DTO carries
            // `_dataContext`, an exception carries `$message` -- and neither is
            // state this class chose to keep.
            if ($property->isReadOnly() || $property->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            $offences[] = $class.'::$'.$property->getName().' is mutable (reached from '.$seed.')';
        }
    }

    return $offences;
}

it('reaches the state a shell event actually writes', function (): void {
    $reach = ShellEventGraph::reach();

    foreach (shellStateAnchors() as $class) {
        expect($reach)->toHaveKey($class);
    }

    // Reaching a name is half of it. The walk below reads properties by
    // reflection and steps silently over anything it cannot resolve, so a graph
    // that reached all three and resolved none reports the same clean tree a
    // correct one does.
    $unresolved = array_values(array_filter(
        shellStateAnchors(),
        static fn (string $class): bool => ShellEventGraph::classFile($class) === null || ! class_exists($class),
    ));

    expect($unresolved)->toBe(
        [],
        'The graph names these and the reader cannot open them, so their properties are never read and the rule '
        ."below reports them clean without looking:\n  ".implode("\n  ", $unresolved)
    );
});

it('never lets a shell event keep state in something that ends with the request', function (): void {
    $offences = shellStateOffences();

    expect($offences)->toBe([], implode("\n", array_merge($offences, [
        '',
        'A shell event is one HTTP request to _native/api/events, served by a',
        'PHP process that exits when it answers. Nothing written to a property',
        'here is readable by the next event, by the window, or by the worker --',
        'not even when the provider binds the holder as a singleton, because the',
        'container is rebuilt with the process.',
        '',
        'If the value only has to survive the call, make it a local or a',
        'parameter. If it has to survive the event -- a rolling counter, a flag',
        'the window reads later, a fact somebody claims once --',
        'Modules\\Desktop\\Internal\\Native\\ShellState is the device-local slot it',
        'belongs in, and Modules\\Desktop\\Internal\\Native\\ShellHandoff is the',
        'claim-once shape built on top of it.',
        '',
        'A static property is the same defect in a different spelling: it dies',
        'with the process too, so it is reported here as well.',
    ])));
});
