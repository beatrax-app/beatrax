<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// Livewire runs a `#[Validate]` rule only when the component actually calls
// validate(); the attribute alone enforces nothing. A component that declares
// a rule and never runs it reads as validated in review and accepts anything
// at runtime — how the app lock came to accept a PIN its keypad cannot type.
/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md
 */

/**
 * @return list<string>
 */
function livewireComponentPaths(): array
{
    $paths = [];
    $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules')));
    foreach ($tree as $file) {
        if (! $file instanceof SplFileInfo || ! str_ends_with($file->getFilename(), '.php')) {
            continue;
        }
        if (str_contains($file->getPathname(), '/tests/')) {
            continue;
        }

        $paths[] = $file->getPathname();
    }
    sort($paths);

    return $paths;
}

/**
 * The properties one component declares a rule for and never runs. Named and
 * taking a source string so the control below drives the same reader the walk
 * drives.
 *
 * @return list<string> property names, or [] when the file declares no rule
 */
function declaredValidationUnenforcedIn(string $source): array
{
    if (! str_contains($source, '#[Validate')) {
        return [];
    }

    $declared = PatternScan::all('/#\[Validate\([^\]]*\)\]\s*public\s+\S+\s+\$(\w+)/', $source);
    if ($declared[1] === []) {
        return [];
    }

    // Any validate() call is treated as covering the component, including
    // one passed explicit rules. Only a component that never validates at
    // all is reported, so an unusual arrangement cannot be a false alarm.
    if (preg_match('/\$this->validate\s*\(/', $source) === 1) {
        return [];
    }

    $only = PatternScan::all("/validateOnly\(\s*'(\w+)'/", $source);
    $covered = array_flip($only[1]);

    $unenforced = array_values(array_filter(
        array_unique($declared[1]),
        static fn (string $property): bool => ! isset($covered[$property]),
    ));

    sort($unenforced);

    return $unenforced;
}

it('has every declared validation rule actually enforced', function (): void {
    $paths = livewireComponentPaths();

    // Both denominators. The floor on files sits far under the 6,600 the walk
    // opens; the floor on declarations is read after it, because a walk that
    // found no `#[Validate]` at all is a reader that stopped rather than a tree
    // that validates nothing — four components declare rules today.
    expect(count($paths))->toBeGreaterThan(
        1000,
        'The Modules walk opened almost nothing, so no component was read at all.'
    );

    $hits = [];
    $declaring = 0;

    foreach ($paths as $path) {
        $source = (string) file_get_contents($path);

        if (! str_contains($source, '#[Validate')) {
            continue;
        }

        $declaring++;

        $unenforced = declaredValidationUnenforcedIn($source);

        if ($unenforced !== []) {
            $hits[] = str_replace(base_path().'/', '', $path).' → $'.implode(', $', $unenforced);
        }
    }

    sort($hits);

    expect($declaring)->toBeGreaterThan(
        1,
        'No component declares a #[Validate] rule, so this rule had nothing to hold to a validate() call.'
    );

    expect($hits)->toBe([], "A #[Validate] rule is enforced only when the component calls validate(). These declare rules nothing ever runs:\n  ".implode("\n  ", $hits));
});

// The guard is worth its ability to go red. A declaration reader that matched
// nothing would report every component as validated.
it('sees a rule nothing runs, and credits both ways of running one', function (): void {
    $unenforced = <<<'PHP'
        <?php
        final class Planted
        {
            #[Validate('required|digits:6')]
            public string $pin = '';

            public function save(): void
            {
                $this->store($this->pin);
            }
        }
        PHP;

    $validated = str_replace('$this->store($this->pin);', '$this->validate();', $unenforced);
    $only = str_replace('$this->store($this->pin);', "\$this->validateOnly('pin');", $unenforced);

    expect(declaredValidationUnenforcedIn($unenforced))->toBe(['pin']);
    expect(declaredValidationUnenforcedIn($validated))->toBe([], 'A component that calls validate() enforces every rule it declares and must not be reported.');
    expect(declaredValidationUnenforcedIn($only))->toBe([], 'validateOnly() covers the property it names and must not be reported.');
    expect(declaredValidationUnenforcedIn("<?php\nfinal class NoRules { public string \$pin = ''; }\n"))->toBe(
        [],
        'A component declaring no rule has nothing for a validate() call to enforce.'
    );
});
