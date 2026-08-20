<?php

declare(strict_types=1);

// Core's App\Models\User class_alias has to be in place before any other
// module's provider tries to resolve it, and provider boot order follows
// module.json priority.
it('Core module has the lowest priority of all modules', function (): void {
    $moduleJsons = glob(base_path('Modules/*/module.json')) ?: [];

    expect($moduleJsons)->not->toBeEmpty();

    $priorities = [];
    foreach ($moduleJsons as $path) {
        $contents = file_get_contents($path);
        expect($contents)->toBeString();
        $decoded = json_decode((string) $contents, true, flags: JSON_THROW_ON_ERROR);
        expect($decoded)->toBeArray();
        $name = $decoded['name'] ?? null;
        $priority = $decoded['priority'] ?? null;
        expect($name)->toBeString();
        expect($priority)->toBeInt();
        $priorities[$name] = $priority;
    }

    expect($priorities)->toHaveKey('Core');

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
