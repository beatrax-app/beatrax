<?php

declare(strict_types=1);

use Livewire\Component;
use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\BackendSourceFiles;
use Tests\Contracts\Support\WireCallableMethods;

// A quarantined operation is evidence of a defect in the merge layer, so the
// screen that lists them is a read-out and nothing else: a button that applies
// one anyway writes a row the system refused for a reason, and it writes it on
// a screen whose whole purpose is to say what went wrong.

const QUARANTINE_TABLE = 'op_log_quarantine';

// Every spelling a template has for reaching the server. `<form` and a submit
// control are here because a POST needs neither a wire action nor Alpine.
const QUARANTINE_ACTION_SPELLINGS = [
    'wire:click',
    'wire:submit',
    'wire:confirm',
    '$wire.',
    'x-on:click',
    '@click',
    '<form',
    'type="submit"',
];

/** @return list<class-string<Component>> the components that read the quarantine table */
function quarantineSurfaceComponents(): array
{
    $found = [];

    foreach (WireCallableMethods::components() as $component) {
        $file = (new ReflectionClass($component))->getFileName();

        if ($file === false) {
            continue;
        }

        $source = implode('', array_map(
            static fn (array|string $token): string => is_array($token) ? $token[1] : $token,
            BackendSourceFiles::codeTokens($file),
        ));

        if (str_contains($source, QUARANTINE_TABLE)) {
            $found[] = $component;
        }
    }

    return $found;
}

/**
 * The templates a component names, resolved through the view finder so a
 * renamed view is a missing template rather than a silently empty scan.
 *
 * @param  class-string<Component>  $component
 * @return list<string> absolute paths
 */
function quarantineSurfaceTemplates(string $component): array
{
    $file = (new ReflectionClass($component))->getFileName();

    if ($file === false) {
        return [];
    }

    $source = implode('', array_map(
        static fn (array|string $token): string => is_array($token) ? $token[1] : $token,
        BackendSourceFiles::codeTokens($file),
    ));

    $paths = [];
    $factory = app('view');
    $finder = $factory->getFinder();

    foreach (PatternScan::all('/[\'"]([a-z][a-z0-9_-]*::[a-z0-9._-]+)[\'"]/', $source)[1] as $candidate) {
        if ($factory->exists($candidate)) {
            $paths[] = $finder->find($candidate);
        }
    }

    return array_values(array_unique($paths));
}

/** @return list<string> the action spellings a template offers */
function quarantineSurfaceActionsIn(string $path): array
{
    $markup = PatternScan::replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($path));

    return array_values(array_filter(
        QUARANTINE_ACTION_SPELLINGS,
        static fn (string $spelling): bool => str_contains($markup, $spelling),
    ));
}

it('lists the refused operations from a component the browser can call nothing on', function (): void {
    $components = quarantineSurfaceComponents();

    expect($components)->not->toBe([], 'no component was found reading '.QUARANTINE_TABLE.', so this guard read nothing at all');

    $offenders = [];

    foreach ($components as $component) {
        foreach (WireCallableMethods::invokableOn($component) as $method) {
            $offenders[] = $component.'::'.$method->getName().'()';
        }
    }

    expect($offenders)->toBe(
        [],
        'The screen that lists the operations merge refused exposed a method a crafted payload can call: '.implode(', ', $offenders).".\n".
        "A quarantined op is evidence of a defect, and the answer to it is a fix to the merge layer, never a control that\n".
        "applies what the system already declined. Read-only is the whole contract of this surface: it renders, and that is all.\n".
        'If the screen genuinely needs an action, it is no longer the quarantine read-out and belongs somewhere else.',
    );
});

it('draws the refused operations with a template that offers no control at all', function (): void {
    $components = quarantineSurfaceComponents();
    expect($components)->not->toBe([]);

    $templates = [];

    foreach ($components as $component) {
        foreach (quarantineSurfaceTemplates($component) as $path) {
            $templates[] = $path;
        }
    }

    expect($templates)->not->toBe([], 'the quarantine surface named no template the view finder could resolve, so nothing was scanned');

    $offenders = [];

    foreach ($templates as $path) {
        $actions = quarantineSurfaceActionsIn($path);

        if ($actions !== []) {
            $offenders[] = str_replace(base_path().'/', '', $path).' offers '.implode(', ', $actions);
        }
    }

    expect($offenders)->toBe(
        [],
        'A template that lists the operations merge refused carries a control: '.implode('; ', $offenders).".\n".
        "The reason the surface has no button is not that nobody has written one yet — it is that force-applying a\n".
        'refused operation writes data the merge layer rejected, and the defect it evidences would then be invisible.',
    );
});

it('sees a control planted into a quarantine template', function (): void {
    $planted = tempnam(sys_get_temp_dir(), 'quarantine-surface').'.blade.php';
    file_put_contents($planted, <<<'BLADE'
        {{-- wire:click here is inside a comment and must not count --}}
        <button type="button" wire:click="forceApply({{ $skip->id }})">Apply anyway</button>
        BLADE);

    try {
        $found = quarantineSurfaceActionsIn($planted);
    } finally {
        @unlink($planted);
    }

    expect($found)->toBe(['wire:click']);
});
