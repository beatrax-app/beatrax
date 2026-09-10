<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

/**
 * @link ../../.docs/architecture/livewire-snapshot-secrets.md
 */

// Livewire ships a serialised copy of every public property to the browser on
// every render and every round trip, and puts it back in the DOM as the
// wire:snapshot attribute. A code bound with wire:model is therefore in the
// page for as long as the panel is open, and back on the wire on any unrelated
// round trip the component makes -- a select changed, a toggle flipped.
//
// The app-lock code is deliberately a second gate, worth something the account
// password is not: it is what a locked device costs, and what arming a durable
// wrap of the data key costs. The lock screen has never held one. Digits
// accumulate in the pad's own Alpine state and cross once, as a method
// argument, and the pad renders bullets rather than a control anything can
// read back. The settings screen held five, in inputs, and nothing said so --
// two shapes for one secret, which is how the second one stayed invisible.
//
// A wire:model target IS a component property; Livewire can bind to nothing
// else. So the binding is the whole of what this has to read.

const CODE_PROPERTY_BLADE_FLOOR = 250;

/** @return list<string> */
function codePropertyBlades(): array
{
    $roots = [base_path('Modules'), base_path('resources/views')];
    $found = [];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        /** @var Iterator<string, SplFileInfo> $walk */
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($walk as $file) {
            $path = $file->getPathname();

            if (str_ends_with($path, '.blade.php') && ! str_contains($path, '/tests/')) {
                $found[] = $path;
            }
        }
    }

    sort($found);

    return $found;
}

// The last segment of the binding path, so `form.newPin` is judged on `newPin`,
// split into words the way a reader reads it: `pinned` and `spinner` are not
// codes, and `newPin` and `confirm_pin` are.
function codePropertyNamesACode(string $target): bool
{
    $segments = explode('.', $target);
    $last = (string) end($segments);
    $words = PatternScan::split('/(?<=[a-z0-9])(?=[A-Z])|[^A-Za-z0-9]+/', $last);

    foreach ($words as $word) {
        if (in_array(strtolower($word), ['pin', 'pins'], true)) {
            return true;
        }
    }

    return false;
}

// The element the binding sits on, because a name cannot tell a code from a
// panel named after one: `confirmingChangePin` is the boolean that opens the
// modal, and the modal is not what collects the digits.
const CODE_PROPERTY_COLLECTING_TAGS = ['input', 'textarea', 'select'];

function codePropertyIsCollected(string $source, int $offset): bool
{
    $open = strrpos(substr($source, 0, $offset), '<');

    if ($open === false) {
        return false;
    }

    $tag = strtolower(PatternScan::replace('/[^A-Za-z0-9:_-].*$/s', '', substr($source, $open + 1, 60)));

    return in_array($tag, CODE_PROPERTY_COLLECTING_TAGS, true)
        || str_contains($tag, 'form-field')
        || str_ends_with($tag, '-input');
}

/**
 * Every wire:model in a template that collects a code, as `path:target`.
 *
 * @return list<string>
 */
function codePropertyBindingsIn(string $relativePath, string $source): array
{
    $bound = [];

    /** @var list<array{0:string,1:int}> $hits */
    $hits = PatternScan::allWithOffsets('/wire:model(?:\.[A-Za-z0-9.]+)?="([^"]+)"/', $source)[1] ?? [];

    foreach ($hits as $hit) {
        if (codePropertyNamesACode($hit[0]) && codePropertyIsCollected($source, $hit[1])) {
            $bound[] = $relativePath.':'.$hit[0];
        }
    }

    return $bound;
}

it('binds no code to a component property, because the snapshot is the browser\'s', function (): void {
    $blades = codePropertyBlades();

    expect(count($blades))->toBeGreaterThan(
        CODE_PROPERTY_BLADE_FLOOR,
        'The walk opened '.count($blades).' templates, which is what a reader that stopped reading looks like.',
    );

    $bound = [];

    foreach ($blades as $path) {
        $relative = str_replace(base_path().'/', '', $path);
        $bound = array_merge($bound, codePropertyBindingsIn($relative, (string) file_get_contents($path)));
    }

    expect($bound)->toBe([], implode("\n", [
        'These put a code in the wire snapshot:',
        ...$bound,
        '',
        'A wire:model target is a component property, and a public property is',
        'serialised into the page on every render and every round trip. The code',
        'is a second gate on purpose -- it is what a locked device costs, and',
        'what arming a durable wrap of the data key costs. Do what the lock',
        'screen does: keep the digits in the panel\'s own Alpine scope and send',
        'them once, as a method argument.',
    ]));
});

it('reads a bound code, and passes the shapes that only look like one', function (): void {
    expect(codePropertyBindingsIn('Panel.blade.php', '<input wire:model="newPin" />'))
        ->toBe(['Panel.blade.php:newPin']);
    expect(codePropertyBindingsIn('Panel.blade.php', '<input wire:model.live="form.confirm_pin" />'))
        ->toBe(['Panel.blade.php:form.confirm_pin'], 'a modifier and a nested path are still a property');
    expect(codePropertyBindingsIn('Panel.blade.php', '<x-core::form-field name="pin" wire:model="pin" />'))
        ->toBe(['Panel.blade.php:pin'], 'the wrapper renders the input, so it collects one');

    expect(codePropertyBindingsIn('Panel.blade.php', '<flux:modal wire:model="confirmingChangePin">'))
        ->toBe([], 'the flag that opens a panel named after the code is not the code');

    expect(codePropertyBindingsIn('Panel.blade.php', '<input x-model="newPin" />'))
        ->toBe([], 'Alpine state is not in the snapshot, which is the whole shape being asked for');
    expect(codePropertyBindingsIn('Panel.blade.php', '<input wire:model="pinnedRows" />'))
        ->toBe([], 'pinned is not a code');
    expect(codePropertyBindingsIn('Panel.blade.php', '<input wire:model="confirmingEnroll" />'))
        ->toBe([], 'the flag that opens the panel is not what the panel collects');
    expect(codePropertyBindingsIn('Panel.blade.php', '<input wire:model="spinnerState" />'))
        ->toBe([]);
});
