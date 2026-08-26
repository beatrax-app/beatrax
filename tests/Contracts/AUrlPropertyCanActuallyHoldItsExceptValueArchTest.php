<?php

declare(strict_types=1);

use Livewire\Attributes\Url;
use Livewire\Component;

// #[Url(except: …)] names the value at which the parameter is left out of the
// address bar, and the browser compares it against the property strictly. An
// except the property can never hold means the parameter is written on every
// visit: /reconcile became /reconcile?accountId= and /inboxes became
// /inboxes?reconnect= before anyone had touched a filter, and every bookmark
// and shared link carried it. Leaving except off entirely is the other correct
// answer — Livewire then compares against the mounted value — so null and false
// are not offenders here.
/**
 * @return list<class-string<Component>>
 */
function urlExceptComponents(): array
{
    $files = array_merge(
        glob(base_path('Modules/*/Internal/Http/Livewire/*.php')) ?: [],
        glob(base_path('Modules/*/Public/Http/Livewire/*.php')) ?: [],
    );
    sort($files);

    $classes = [];
    foreach ($files as $file) {
        $relative = str_replace([base_path().'/Modules/', '.php'], '', $file);
        $class = 'Modules\\'.str_replace('/', '\\', $relative);
        if (class_exists($class) && is_subclass_of($class, Component::class)) {
            $classes[] = $class;
        }
    }

    return $classes;
}

it('never gives a URL-bound property an except value it cannot equal', function (): void {
    $offenders = [];

    foreach (urlExceptComponents() as $class) {
        foreach ((new ReflectionClass($class))->getProperties() as $property) {
            $attributes = $property->getAttributes(Url::class);
            if ($attributes === []) {
                continue;
            }

            $except = $attributes[0]->newInstance()->except;
            $type = $property->getType();
            if ($except === null || $except === false || ! $type instanceof ReflectionNamedType) {
                continue;
            }

            if (get_debug_type($except) === $type->getName()) {
                continue;
            }

            $offenders[] = $class.'::$'.$property->getName().' is '
                .($type->allowsNull() ? '?' : '').$type->getName()
                .', except is '.get_debug_type($except);
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These parameters are written into every URL because the value that was',
        'supposed to omit them is one the property can never hold:',
        ...$offenders,
        '',
        'Match except to the type the property declares, or leave it off and let',
        'Livewire compare against the value the component mounted with.',
    ]));
});
