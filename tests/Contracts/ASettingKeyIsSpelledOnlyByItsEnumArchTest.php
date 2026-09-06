<?php

declare(strict_types=1);

use Modules\Community\Public\Enums\CommunitySetting;
use Modules\Core\Public\Support\PatternScan;

// The panel that writes `users.community_settings` and the consumers that gate
// on it sit in different modules, so a key spelled as a literal on either side
// is a toggle that silently stops working — and the second speller reliably
// brings its own copy of the default with it.

// The two backend roots hold 6,688 PHP files with the suite left out, and the
// floor sits far under that: a walk that opened none of them finds no literal
// either, which is the answer a correct tree gives.
const SETTING_KEY_FILE_FLOOR = 1_000;

/** @return list<string> absolute paths to every production PHP file the guard covers */
function settingKeyProductionFiles(): array
{
    $files = [];
    foreach ([base_path('Modules'), base_path('app')] as $root) {
        if (! is_dir($root)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (! $file->isFile() || ! str_ends_with($path, '.php')) {
                continue;
            }

            // The suite spells these keys on purpose, to assert about the panel
            // that writes them. Seeders and migrations are in scope: a seeded
            // default is a second copy of the enum's own, which is the shape
            // this rule exists to stop.
            if (str_contains($path, '/tests/')) {
                continue;
            }

            $files[] = $path;
        }
    }
    sort($files);

    return $files;
}

// A subscript and an array_key_exists are the two read shapes; the write side
// `$settings['key'] = ...` is a subscript too. A `'key' => $value` pair is
// neither, which is how a view-data key of the same spelling stays legal.
/** @return list<string> the offending `path — key` pairs in one file */
function settingKeyLiteralHits(string $path, string $source, string $key): array
{
    $quoted = preg_quote($key, '/');

    foreach ([
        '/\[\s*([\'"])'.$quoted.'\1\s*\]/',
        '/array_key_exists\(\s*([\'"])'.$quoted.'\1/',
    ] as $pattern) {
        if (PatternScan::matches($pattern, $source)) {
            return [str_replace(base_path().'/', '', $path).' — '.$key];
        }
    }

    return [];
}

// The enum itself is in the walk like any other file. It declares its keys as
// enum cases rather than reading them out of an array, so the two shapes below
// find nothing in it and a step-over would excuse nothing.
it('has no community-settings key spelled as a literal outside its enum', function (): void {
    $files = settingKeyProductionFiles();
    $hits = [];

    foreach ($files as $path) {
        $source = (string) file_get_contents($path);
        foreach (CommunitySetting::cases() as $setting) {
            $hits = array_merge($hits, settingKeyLiteralHits($path, $source, $setting->value));
        }
    }

    sort($hits);

    expect(count($files))->toBeGreaterThan(
        SETTING_KEY_FILE_FLOOR,
        'The walk opened '.count($files).' backend files, so a clean answer here is a walk that read almost nothing.'
    );

    expect($hits)->toBe([], implode("\n", [
        'These spell a users.community_settings key as a literal instead of asking',
        'CommunitySetting for it, which is how a reader picks up a second copy of',
        'the default that the enum already owns:',
        ...$hits,
    ]));
});

it('has the enum still owning every key the guard scans for', function (): void {
    expect(array_map(
        static fn (CommunitySetting $setting): string => $setting->value,
        CommunitySetting::cases(),
    ))->toBe(
        ['useSharedList', 'offerToContribute', 'updateOnAppUpdates'],
        'The rule above looks for exactly the keys this enum declares, so a key that leaves the enum leaves the '
        .'scan with it and every literal spelling of it goes unread.'
    );
});

// A guard that cannot go red says nothing, and the verdict above is read off one
// reader of two shapes. It is checked against the shapes it was written for
// rather than against the tree.
it('reads the two shapes a key is spelled in, and leaves a pair alone', function (string $line, bool $spells): void {
    $found = settingKeyLiteralHits('/tmp/probe.php', $line, 'useSharedList');

    expect($found !== [])->toBe(
        $spells,
        'The reader answered '.var_export(! $spells, true).' for a line it has to read as '
        .($spells ? 'a literal spelling of the key' : 'something else').': '.$line
    );
})->with([
    'a read by subscript' => ["\$on = \$settings['useSharedList'];", true],
    'a write by subscript' => ["\$settings['useSharedList'] = true;", true],
    'the double-quoted spelling' => ['$on = $settings["useSharedList"];', true],
    'a presence check' => ["if (array_key_exists('useSharedList', \$settings)) {", true],
    'a pair in a view-data array' => ["return ['useSharedList' => \$on];", false],
    'the enum case that owns it' => ["case UseSharedList = 'useSharedList';", false],
    'a different key' => ["\$on = \$settings['offerToContribute'];", false],
]);
