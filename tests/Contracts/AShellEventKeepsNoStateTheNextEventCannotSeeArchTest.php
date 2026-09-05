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

/**
 * @return list<string>
 */
function shellStateOffences(): array
{
    $offences = [];

    foreach (ShellEventGraph::reach() as $class => $seed) {
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

    expect($reach)->toHaveKey('Modules\\Desktop\\Internal\\Native\\WindowFocusState');
    expect($reach)->toHaveKey('Modules\\Desktop\\Internal\\Listeners\\SurfaceWorkerCrashAlert');
    expect($reach)->toHaveKey('Modules\\Desktop\\Internal\\Native\\ShellState');
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
