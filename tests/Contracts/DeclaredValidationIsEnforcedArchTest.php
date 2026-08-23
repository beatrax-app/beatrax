<?php

declare(strict_types=1);

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

it('has every declared validation rule actually enforced', function (): void {
    $hits = [];

    foreach (livewireComponentPaths() as $path) {
        $source = (string) file_get_contents($path);
        if (! str_contains($source, '#[Validate')) {
            continue;
        }

        preg_match_all('/#\[Validate\([^\]]*\)\]\s*public\s+\S+\s+\$(\w+)/', $source, $declared);
        if ($declared[1] === []) {
            continue;
        }

        // Any validate() call is treated as covering the component, including
        // one passed explicit rules. Only a component that never validates at
        // all is reported, so an unusual arrangement cannot be a false alarm.
        if (preg_match('/\$this->validate\s*\(/', $source) === 1) {
            continue;
        }

        preg_match_all("/validateOnly\(\s*'(\w+)'/", $source, $only);
        $covered = array_flip($only[1]);

        $unenforced = array_values(array_filter(
            array_unique($declared[1]),
            static fn (string $property): bool => ! isset($covered[$property]),
        ));

        if ($unenforced !== []) {
            sort($unenforced);
            $hits[] = str_replace(base_path().'/', '', $path).' → $'.implode(', $', $unenforced);
        }
    }

    sort($hits);

    expect($hits)->toBe([], "A #[Validate] rule is enforced only when the component calls validate(). These declare rules nothing ever runs:\n  ".implode("\n  ", $hits));
});
