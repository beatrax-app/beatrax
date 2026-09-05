<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UpdateCheckPreference;
use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\BackendSourceFiles;

// The update check is the one outbound call this application makes by default,
// and the privacy stance says it must be possible to stop. It was not: the only
// lever was an env var the release pipeline wrote into the bundle's own `.env`,
// which nobody who installs a bundle can reach — and a second env var whose
// meaning inverted between build time and runtime made the gap hard to see.
// This guard holds every half of the switch: a value a reader sets, a screen
// that sets it, and both callers consulting it before they reach the network.

const UPDATE_SWITCH_COLUMN = 'auto_update_check_enabled';

// The two configuration keys the feature reaches the network through. The first
// composes the manifest URL Beatrax fetches itself; the second is what the
// Electron main process checks before electron-updater polls at all.
const UPDATE_SWITCH_FEED_KEY = 'auto_update.manifest_feed_url';
const UPDATE_SWITCH_BOOT_KEY = 'nativephp.updater.enabled';

// The class every one of them has to name. A guard that accepted any mention
// would pass on a comment, so the subject is always the comment-free source.
const UPDATE_SWITCH_SEAM = 'UpdateCheckPreference';

/**
 * The file's own source with every comment removed, so prose never answers for
 * code. Memoized: six scans over the whole backend tree tokenise the same
 * two thousand files, and the answer for a path cannot change mid-run.
 */
function updateSwitchCode(string $path): string
{
    static $seen = [];

    if (isset($seen[$path])) {
        return $seen[$path];
    }

    $code = '';

    foreach (BackendSourceFiles::codeTokens($path) as $token) {
        $code .= is_array($token) ? $token[1] : $token;
    }

    return $seen[$path] = $code;
}

/**
 * Production files whose CODE contains the literal.
 *
 * @return list<string> repo-relative paths
 */
function updateSwitchFilesNaming(string $literal): array
{
    $hits = [];

    foreach (BackendSourceFiles::all() as $path) {
        if (str_contains(updateSwitchCode($path), $literal)) {
            $hits[] = str_replace(base_path().'/', '', $path);
        }
    }

    sort($hits);

    return array_values(array_unique($hits));
}

/**
 * Both composer roots run this suite, and from mobile-app/ the desktop tree sits
 * one level up. Anchored on release.yml rather than tried per file: mobile-app/
 * has a config/nativephp.php of its OWN, with no updater section at all, so a
 * per-file `is_file()` fallback would resolve the wrong one and pass on it.
 */
function updateSwitchDesktopRoot(): string
{
    return is_file(base_path('.github/workflows/release.yml'))
        ? base_path()
        : base_path('..');
}

/** @return list<string> every Blade template in the module tree */
function updateSwitchBladeFiles(): array
{
    $blades = [];

    $root = base_path('Modules');

    if (! is_dir($root)) {
        return [];
    }

    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
    ) as $file) {
        $path = $file->getPathname();

        if ($file->isFile() && str_ends_with($path, '.blade.php')) {
            $blades[] = $path;
        }
    }

    sort($blades);

    return $blades;
}

it('lets nothing compose the update feed URL without asking the reader first', function (): void {
    $composers = updateSwitchFilesNaming("'".UPDATE_SWITCH_FEED_KEY."'");

    // The denominator. A rename that empties this scan would otherwise report a
    // tree where every feed reader consults the switch, having read none.
    expect($composers)->not->toBe([], 'nothing reads '.UPDATE_SWITCH_FEED_KEY.' — this guard just scanned an empty tree');

    $offenders = [];
    foreach ($composers as $relative) {
        if (! PatternScan::matches('/\b'.UPDATE_SWITCH_SEAM.'\b/', updateSwitchCode(base_path($relative)))) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([], implode("\n  ", [
        'These build the update feed URL without consulting the answer a reader can actually',
        'set. An env var baked into a shipped bundle\'s own .env is not an off switch: nobody',
        'who installs the bundle can reach it. Take the decision through '.UPDATE_SWITCH_SEAM.',',
        'which is the one place that reads the stored column.',
        'Offenders:',
        ...$offenders,
    ]));
});

it('narrows the Electron boot poll by the same answer', function (): void {
    $configPath = updateSwitchDesktopRoot().'/config/nativephp.php';

    expect(is_file($configPath))->toBeTrue('the desktop config/nativephp.php was not found from either composer root');
    expect((string) file_get_contents($configPath))->toContain(
        "'updater' =>",
        'the boot-time updater key this guard is about has gone — rewrite the guard, do not delete it',
    );

    $narrowers = [];
    foreach (updateSwitchFilesNaming("'".UPDATE_SWITCH_BOOT_KEY."'") as $relative) {
        if (PatternScan::matches('/\b'.UPDATE_SWITCH_SEAM.'\b/', updateSwitchCode(base_path($relative)))) {
            $narrowers[] = $relative;
        }
    }

    expect($narrowers)->not->toBe([], implode("\n  ", [
        'Nothing narrows '.UPDATE_SWITCH_BOOT_KEY.' by the reader\'s answer. That key is read',
        'in the Electron main process, out of the JSON the `native:config` command prints, and it',
        'decides whether electron-updater polls the feed at every launch — before the app is even',
        'served, so no listener downstream of it can hold that call back. A switch that stops only',
        'the PHP fetch leaves this one making the call the reader asked nobody to make.',
    ]));
});

it('keeps the answer in a column a reader sets, on a screen that is mounted', function (): void {
    expect(class_exists(UpdateCheckPreference::class))->toBeTrue(
        'the seam that reads the stored answer does not exist, so nothing a reader sets can reach the update check',
    );
    expect(UpdateCheckPreference::COLUMN)->toBe(
        UPDATE_SWITCH_COLUMN,
        'the seam names a different column than this guard was written against',
    );

    $writers = [];
    foreach (updateSwitchFilesNaming("'".UPDATE_SWITCH_COLUMN."'") as $relative) {
        $code = updateSwitchCode(base_path($relative));

        if (PatternScan::matches('/\bWriteUserPreference\b/', $code)) {
            $writers[] = $relative;
        }
    }

    expect($writers)->not->toBe([], implode("\n  ", [
        'No screen writes users.'.UPDATE_SWITCH_COLUMN.'. A column nothing sets is a default,',
        'not a switch: the reader has no way to change it and the guard above is satisfied by a',
        'value that never moves.',
    ]));

    $providers = glob(base_path('Modules/*/Providers/*.php')) ?: [];
    expect($providers)->not->toBe([], 'no module providers were found — this guard just scanned an empty tree');

    $blades = implode("\n", array_map(
        static fn (string $path): string => (string) file_get_contents($path),
        updateSwitchBladeFiles(),
    ));
    expect($blades)->not->toBe('', 'no Blade templates were found — this guard just scanned an empty tree');

    $unreachable = [];
    foreach ($writers as $relative) {
        $short = basename($relative, '.php');
        $aliases = [];

        foreach ($providers as $providerPath) {
            $matches = PatternScan::all(
                '/component\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*'.preg_quote($short, '/').'::class/',
                (string) file_get_contents($providerPath),
            );

            foreach ($matches[1] as $alias) {
                $aliases[] = $alias;
            }
        }

        if ($aliases === []) {
            $unreachable[] = $relative.' writes the column but no provider registers it as a Livewire component';

            continue;
        }

        foreach ($aliases as $alias) {
            // Substrings, not a tag-shaped pattern: a mount is a NAME, no
            // attribute is read, and the guard that bans markup regexes in this
            // directory is right that a pattern here would answer nothing extra.
            $mounted = str_contains($blades, "@livewire('".$alias."'")
                || str_contains($blades, '@livewire("'.$alias.'"')
                || str_contains($blades, '<livewire:'.$alias);

            if (! $mounted) {
                $unreachable[] = $relative.' is registered as `'.$alias.'` and no Blade template mounts it';
            }
        }
    }

    expect($unreachable)->toBe([], implode("\n  ", [
        'The switch is written but never drawn. A component nothing mounts is a control the reader',
        'cannot reach, which is the defect this guard exists for rather than a smaller version of it.',
        'Offenders:',
        ...$unreachable,
    ]));
});

it('leaves neither reader of the build flag resting on a default', function (): void {
    $workflowPath = updateSwitchDesktopRoot().'/.github/workflows/release.yml';
    expect(is_file($workflowPath))->toBeTrue('release.yml was not found from either composer root');

    $workflow = (string) file_get_contents($workflowPath);

    // The build half: electron-builder.mjs compares this to the STRING 'true',
    // so an unset variable silently means "no publish block, no feed in the
    // bundle" there while config/nativephp.php reads the same name as true.
    expect(PatternScan::matches('/^\s*NATIVEPHP_UPDATER_ENABLED:\s*\S/m', $workflow))->toBeTrue(
        'release.yml does not set NATIVEPHP_UPDATER_ENABLED for the build, so electron-builder falls '
        .'back to false and ships a bundle with no update feed while the PHP config says the check is on',
    );

    // The runtime half: every bundled .env that is given the feed origin must
    // be given the flag beside it, or the two disagree inside one bundle.
    $feedWrites = PatternScan::count('/printf\s+\'AUTO_UPDATE_FEED_URL=/', $workflow);
    $flagWrites = PatternScan::count('/printf\s+\'NATIVEPHP_UPDATER_ENABLED=/', $workflow);

    expect($feedWrites)->toBeGreaterThan(0, 'no staging step writes the feed origin — this guard just scanned the wrong file');
    expect($flagWrites)->toBe($feedWrites, sprintf(
        '%d staging steps write AUTO_UPDATE_FEED_URL into the bundled .env and %d write '
        .'NATIVEPHP_UPDATER_ENABLED beside it. A bundle that carries the feed but not the flag leaves '
        .'the runtime half on a default nobody chose.',
        $feedWrites,
        $flagWrites,
    ));
});

it('reads a planted caller and is not fooled by one that only mentions the seam', function (): void {
    $consulting = tempnam(sys_get_temp_dir(), 'update-switch-consulting').'.php';
    file_put_contents($consulting, <<<'PHP'
        <?php
        final class PlantedConsultingFetcher
        {
            public function url(): ?string
            {
                if (! $this->preference->enabled()) {
                    return null;
                }

                return $this->config->get('auto_update.manifest_feed_url');
            }
        }
        PHP);

    $mentioning = tempnam(sys_get_temp_dir(), 'update-switch-mentioning').'.php';
    file_put_contents($mentioning, <<<'PHP'
        <?php
        final class PlantedMentioningFetcher
        {
            // One day this will go through UpdateCheckPreference.
            public function url(): ?string
            {
                return $this->config->get('auto_update.manifest_feed_url');
            }
        }
        PHP);

    try {
        $consultingCode = updateSwitchCode($consulting);
        $mentioningCode = updateSwitchCode($mentioning);
    } finally {
        @unlink($consulting);
        @unlink($mentioning);
    }

    expect(str_contains($consultingCode, "'".UPDATE_SWITCH_FEED_KEY."'"))->toBeTrue();
    expect(str_contains($mentioningCode, "'".UPDATE_SWITCH_FEED_KEY."'"))->toBeTrue();

    // The point of stripping comments: the second file names the seam in prose
    // and calls nothing, which is exactly the shape a laxer scan would clear.
    expect(PatternScan::matches('/\bUpdateCheckPreference\b/', $mentioningCode))->toBeFalse();
    expect(PatternScan::matches('/\bpreference->enabled\(\)/', $consultingCode))->toBeTrue();
});

it('leaves no key in the update configuration that no runtime code reads', function (): void {
    $configPath = updateSwitchDesktopRoot().'/config/auto_update.php';

    expect(is_file($configPath))->toBeTrue('config/auto_update.php was not found from either composer root');

    $keys = PatternScan::all('/^\s{4}\'([a-z0-9_]+)\'\s*=>/m', (string) file_get_contents($configPath))[1];

    // A config key that reads as a control and controls nothing is worse than
    // an absent one: it is what made the missing off switch hard to see, and
    // this file is the one whose keys are Beatrax's own to answer for.
    expect(count($keys))->toBeGreaterThan(2, 'the update configuration was read as having almost no keys — the scan is wrong, not the file');

    $unread = [];
    foreach ($keys as $key) {
        if (updateSwitchFilesNaming("'auto_update.".$key."'") === []) {
            $unread[] = 'auto_update.'.$key;
        }
    }

    expect($unread)->toBe([], implode("\n  ", [
        'These configuration keys are read by nothing in the application. Wire them up or delete',
        'them — a key that looks like a lever and moves nothing is how a missing switch stays',
        'invisible to everybody reading the config file for one.',
        'Offenders:',
        ...$unread,
    ]));
});
