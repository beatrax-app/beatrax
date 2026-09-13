<?php

declare(strict_types=1);

use Modules\Core\Providers\CoreServiceProvider;

// Core's App\Models\User class_alias has to be in place before any other
// module's provider tries to resolve it. Two different mechanisms decide that
// order, and this file holds both: module.json priority, which nwidart applies
// to the twenty-four modules listed in modules_statuses.json, and
// bootstrap/providers.php, which orders the other eleven.
//
// @link ../../.docs/architecture/module-boundaries.md#how-the-thirty-five-providers-get-ordered
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
    // quietly. Ten modules declare no manifest at all, and Anomaly declares one
    // that nwidart never reads because it is absent from modules_statuses.json;
    // the boot-order rule below is what covers those eleven.
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

// The rule above reads manifests. This one reads the boot that actually
// happened, so it covers all thirty-five modules however each one got ordered:
// nwidart registers its twenty-four enabled modules by ascending priority, and
// Laravel registers the rest from bootstrap/providers.php afterwards. Measured
// on this tree: forty provider classes across thirty-five modules, with
// CoreServiceProvider at position 31 of 93 and every module provider outside
// Core after it.
it('every module provider outside Core registers after the alias Core installs', function (): void {
    $positions = [];

    foreach (array_keys(app()->getLoadedProviders()) as $index => $provider) {
        $segments = explode('\\', $provider);

        if (count($segments) < 3 || $segments[0] !== 'Modules') {
            continue;
        }

        $positions[$provider] = ['module' => $segments[1], 'index' => $index];
    }

    $core = $positions[CoreServiceProvider::class]['index'] ?? null;

    expect($core)->toBeInt(
        CoreServiceProvider::class.' did not register during this boot, so nothing installed the App\Models\User '
        .'alias and the comparison below has no reference point.'
    );

    $tooEarly = [];
    foreach ($positions as $provider => $entry) {
        if ($entry['module'] !== 'Core' && $entry['index'] < $core) {
            $tooEarly[] = sprintf('%s at %d', $provider, $entry['index']);
        }
    }

    expect($tooEarly)->toBe([], sprintf(
        'These providers registered before %s at position %d, so each one ran while App\Models\User was still '
        ."undefined:\n%s",
        CoreServiceProvider::class,
        (int) $core,
        implode("\n", $tooEarly)
    ));

    // An exact set, not a count: a module whose provider quietly stops
    // registering is ordered by nothing at all, and the manifest rule above
    // cannot see it because it reads the tree rather than the boot.
    $directories = [];
    foreach ((array) glob(base_path('Modules/*'), GLOB_ONLYDIR) as $directory) {
        if (is_string($directory)) {
            $directories[] = basename($directory);
        }
    }

    sort($directories);

    $registered = array_values(array_unique(array_column($positions, 'module')));
    sort($registered);

    expect($registered)->toBe($directories, sprintf(
        'Modules/ holds %d directories but %d of them registered a provider during this boot. Missing: %s. '
        .'Unexpected: %s.',
        count($directories),
        count($registered),
        implode(', ', array_diff($directories, $registered)) ?: 'none',
        implode(', ', array_diff($registered, $directories)) ?: 'none'
    ));
});
