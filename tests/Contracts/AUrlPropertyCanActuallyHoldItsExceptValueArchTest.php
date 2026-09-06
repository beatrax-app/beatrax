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
 * Walked rather than globbed at one depth: components live in Steps/ and other
 * subdirectories too, and a flat glob would report a clean tree over a folder
 * nobody opened.
 *
 * @return list<class-string<Component>>
 */
function urlExceptComponents(): array
{
    $files = [];

    foreach (glob(base_path('Modules/*/{Internal,Public}/Http/Livewire'), GLOB_ONLYDIR | GLOB_BRACE) ?: [] as $directory) {
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && str_ends_with($file->getPathname(), '.php')) {
                $files[] = $file->getPathname();
            }
        }
    }

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

/**
 * Whether a property's declared type can never hold the except value beside it.
 * A union type answers null — it is not a shape this reads, and saying so is
 * what keeps it out of the verdict rather than silently inside it.
 */
function urlExceptMismatch(mixed $except, ?ReflectionType $type): ?bool
{
    if ($except === null || $except === false || ! $type instanceof ReflectionNamedType) {
        return null;
    }

    return get_debug_type($except) !== $type->getName();
}

it('never gives a URL-bound property an except value it cannot equal', function (): void {
    $components = urlExceptComponents();

    // Every Livewire page in the tree stands behind this. A run that resolved a
    // handful read a broken walk, not a tree that binds nothing to a URL.
    expect(count($components))->toBeGreaterThan(
        50,
        'The walk resolved almost no Livewire components, so the empty offender list below is a tree nobody read.',
    );

    $offenders = [];
    $attributesRead = 0;

    foreach ($components as $class) {
        foreach ((new ReflectionClass($class))->getProperties() as $property) {
            $attributes = $property->getAttributes(Url::class);
            if ($attributes === []) {
                continue;
            }

            $attributesRead++;
            $type = $property->getType();

            if (urlExceptMismatch($attributes[0]->newInstance()->except, $type) !== true) {
                continue;
            }

            /** @var ReflectionNamedType $type */
            $offenders[] = $class.'::$'.$property->getName().' is '
                .($type->allowsNull() ? '?' : '').$type->getName()
                .', except is '.get_debug_type($attributes[0]->newInstance()->except);
        }
    }

    expect($attributesRead)->toBeGreaterThan(
        20,
        'No #[Url] attribute was read at all, so this rule checked nothing.',
    );

    expect($offenders)->toBe([], implode("\n", [
        'These parameters are written into every URL because the value that was',
        'supposed to omit them is one the property can never hold:',
        ...$offenders,
        '',
        'Match except to the type the property declares, or leave it off and let',
        'Livewire compare against the value the component mounted with.',
    ]));
});

// The verdict above is read off a list that is empty on a clean tree and on a
// predicate that stopped answering. This plants each answer it has to give.
it('sees an except a property cannot hold, and leaves the three that are legal alone', function (): void {
    $string = (new ReflectionClass(new class
    {
        public ?string $filter = null;
    }))->getProperty('filter')->getType();

    expect(urlExceptMismatch('', $string))->toBeFalse('an empty string on a ?string property is the correct except');
    expect(urlExceptMismatch(0, $string))->toBeTrue('an int except on a string property went unreported');
    expect(urlExceptMismatch(null, $string))->toBeNull('leaving except off is the other correct answer and is not a verdict');
    expect(urlExceptMismatch(false, $string))->toBeNull('the default except is not a verdict either');
    expect(urlExceptMismatch('', null))->toBeNull('an untyped property has no shape to compare against');
});
