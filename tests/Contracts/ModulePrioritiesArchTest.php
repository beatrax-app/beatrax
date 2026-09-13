<?php

declare(strict_types=1);

// Core's App\Models\User class_alias has to be in place before any other
// module's provider tries to resolve it, and provider boot order follows
// module.json priority.
it('Core module has the lowest priority of all modules', function (): void {
    $moduleJsons = glob(base_path('Modules/*/module.json')) ?: [];

    // Read before the comparison below: that loop is vacuously satisfied by an
    // empty tree, and a glob that resolved nothing reports the same green a
    // correctly ordered tree does. The floor sits far under today's 25.
    expect(count($moduleJsons))->toBeGreaterThan(
        10,
        'Only '.count($moduleJsons).' module manifests were found under Modules/*/module.json, which is too '
        .'few to be this repository. Every priority comparison below would pass over almost nothing.'
    );

    // The floor is worth one failure and no more: narrowed to Modules/[A-S]*
    // the glob still matched twenty-four manifests, and a Tax priority set
    // below Core's went unreported. Which directories hold a manifest is read
    // per directory rather than off the same pattern, so a glob that stops
    // matching some of them disagrees with the tree instead of shrinking
    // quietly. Ten modules declare no manifest at all and are not in either
    // list: this rule orders the ones that do.
    $declaring = [];

    foreach ((array) glob(base_path('Modules/*'), GLOB_ONLYDIR) as $directory) {
        if (is_string($directory) && is_file($directory.'/module.json')) {
            $declaring[] = basename($directory);
        }
    }

    sort($declaring);

    expect(array_map(static fn (string $path): string => basename(dirname($path)), $moduleJsons))->toBe(
        $declaring,
        'The manifest glob and a walk of the module directories disagree about which modules declare one, so '
        .'the ordering below is asserted over whichever subset the pattern still matches.'
    );

    $priorities = [];
    foreach ($moduleJsons as $path) {
        $contents = file_get_contents($path);
        expect($contents)->toBeString($path.' could not be read.');
        $decoded = json_decode((string) $contents, true, flags: JSON_THROW_ON_ERROR);
        expect($decoded)->toBeArray($path.' does not decode to an object.');
        $name = $decoded['name'] ?? null;
        $priority = $decoded['priority'] ?? null;
        expect($name)->toBeString($path.' declares no string "name".');
        expect($priority)->toBeInt($path.' declares no integer "priority", so nothing orders its provider.');
        $priorities[$name] = $priority;
    }

    expect($priorities)->toHaveKey('Core', message: 'No module.json declares the name "Core", so the alias this rule protects is registered by nobody.');

    $corePriority = $priorities['Core'];
    foreach ($priorities as $name => $priority) {
        if ($name === 'Core') {
            continue;
        }
        expect($priority)->toBeGreaterThan(
            $corePriority,
            sprintf('Module %s has priority %d which is not strictly greater than Core (%d).', $name, $priority, $corePriority),
        );
    }
});
