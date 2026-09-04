<?php

declare(strict_types=1);

use Modules\Community\Public\Enums\CommunitySetting;
use Modules\Core\Public\Support\PatternScan;

// The panel that writes `users.community_settings` and the consumers that gate
// on it sit in different modules, so a key spelled as a literal on either side
// is a toggle that silently stops working — and the second speller reliably
// brings its own copy of the default with it.

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
            if (str_contains($path, '/tests/') || str_contains($path, '/Database/')) {
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

it('has no community-settings key spelled as a literal outside its enum', function (): void {
    $owner = base_path('Modules/Community/Public/Enums/CommunitySetting.php');
    $hits = [];

    foreach (settingKeyProductionFiles() as $path) {
        if ($path === $owner) {
            continue;
        }
        $source = (string) file_get_contents($path);
        foreach (CommunitySetting::cases() as $setting) {
            $hits = array_merge($hits, settingKeyLiteralHits($path, $source, $setting->value));
        }
    }

    sort($hits);

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
    ))->toBe(['useSharedList', 'offerToContribute', 'updateOnAppUpdates']);
});
