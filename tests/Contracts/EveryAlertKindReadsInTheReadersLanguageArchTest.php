<?php

declare(strict_types=1);

use Modules\Core\Internal\Enums\BackupAlertKind;
use Modules\Core\Public\Enums\OAuthAlertKind;
use Modules\Core\Public\Enums\UpdateAlertKind;
use Modules\Core\Public\Support\PatternScan;

// The banner picks its copy per alert kind and falls through to the row's own
// `message` column for a kind it does not know. That column holds whatever
// English the writer put there, so a missing case is not a missing case -- it
// is English on a Dutch screen, in a banner that at critical severity is
// telling the reader their tokens may be unredacted. All three OAuth kinds
// were missing, next to a backup alert that read correctly in Dutch.

/** @return array<string, string> kind value => how the blade must reference it */
function alertKindsTheBannerMustRender(): array
{
    $kinds = [];

    foreach ([BackupAlertKind::class, OAuthAlertKind::class, UpdateAlertKind::class] as $enum) {
        $short = substr((string) strrchr($enum, '\\'), 1);

        foreach ($enum::cases() as $case) {
            $kinds[(string) $case->value] = $short.'::'.$case->name.'->value';
        }
    }

    foreach (alertKindLiteralsWrittenInProduction() as $kind) {
        $kinds[$kind] = sprintf("@case ('%s')", $kind);
    }

    return $kinds;
}

// Every spelling a file that raises an alert can carry. Matched as a LIST and
// not by a shared prefix: `raiseOnceForUser` does not contain `raiseForUser`,
// so the one guard that looked for the substring skipped both app-lock kinds
// for as long as they were the only alerts their file wrote.
/** @return list<string> */
function alertWriterSpellings(): array
{
    return [
        'raiseForUser',
        'raiseOnceForUser',
        'raiseOnceSystemWide',
        'SystemAlert::create',
        'SystemAlert::query()->create',
        'ALERT_KIND',
    ];
}

// Most kinds are literals rather than enum cases, and a hand-written list of
// them is a list that goes stale the first time a module raises a new one. The
// writers are found instead, and their literals read off them.
/** @return list<string> */
function alertKindLiteralsWrittenInProduction(): array
{
    $kinds = [];

    /** @var iterable<SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules')));

    foreach ($files as $file) {
        $path = $file->getPathname();

        if (! $file->isFile() || ! str_ends_with($path, '.php')) {
            continue;
        }
        // A seeder writes the demo corpus rather than raising an alert a reader
        // is shown, and its rows carry the kinds the writers below already
        // declare — reading it would only report each of them a second time.
        if (str_contains($path, '/tests/') || str_contains($path, '/Seeders/')) {
            continue;
        }

        $source = (string) file_get_contents($path);

        $raisesAnAlert = false;
        foreach (alertWriterSpellings() as $spelling) {
            if (str_contains($source, $spelling)) {
                $raisesAnAlert = true;
                break;
            }
        }

        if (! $raisesAnAlert) {
            continue;
        }

        $patterns = [
            "/(?:kind:\s*|'kind'\s*=>\s*|ALERT_KIND\s*=\s*)'([a-z0-9_.]+)'/",
            "/emitAlert\([^,]+,\s*'([a-z0-9_.]+)'/",
        ];

        foreach ($patterns as $pattern) {
            $matches = PatternScan::all($pattern, $source);

            foreach ($matches[1] as $kind) {
                $kinds[] = $kind;
            }
        }
    }

    $kinds = array_values(array_unique($kinds));
    sort($kinds);

    return $kinds;
}

it('gives every alert kind the app can write its own localised case', function (): void {
    $blade = (string) file_get_contents(base_path(
        'Modules/Core/Resources/views/livewire/partials/system-alert-message.blade.php',
    ));

    $kinds = alertKindsTheBannerMustRender();

    // Three enums and the writers' own literals name 20 kinds between them today.
    // A reader that found none of them reports every kind handled without opening one.
    expect(count($kinds))->toBeGreaterThan(
        10,
        'No alert kind was found at all, so this rule held the banner to nothing.'
    );

    $unhandled = [];
    foreach ($kinds as $kind => $reference) {
        if (! str_contains($blade, $reference)) {
            $unhandled[] = $kind;
        }
    }

    expect($unhandled)->toBe([], 'These kinds fall through to the raw message column, so they '
        .'render in English whatever the reader chose: '.implode(', ', $unhandled));
});

// A case that resolves no copy is the same defect one step later, so the key
// each case names has to exist -- and in every locale, not only in English.
it('backs each of those cases with a key every locale carries', function (): void {
    $blade = (string) file_get_contents(base_path(
        'Modules/Core/Resources/views/livewire/partials/system-alert-message.blade.php',
    ));

    $matches = PatternScan::all("/core::alerts\.messages\.([a-z0-9_]+)/", $blade);

    // The banner names 25 message keys today. A run that read none of them has
    // nothing to hold the locales to and reports every one of them present.
    expect(count($matches[1]))->toBeGreaterThan(
        10,
        'The banner names no localised message key at all, so this rule checked nothing.'
    );

    $keys = array_values(array_unique($matches[1]));
    sort($keys);

    $files = (array) glob(base_path('Modules/Core/Resources/lang/*/alerts.php'));

    // 26 languages ship. A glob that came back short would report the missing
    // ones as complete rather than as missing.
    expect(count($files))->toBeGreaterThan(
        20,
        'Almost no locale alerts file was found, so a missing line would read as present.'
    );

    $missing = [];
    foreach ($files as $file) {
        /** @var array{messages?: array<string, string>} $translations */
        $translations = require $file;
        $locale = basename(dirname((string) $file));

        foreach ($keys as $key) {
            $value = $translations['messages'][$key] ?? null;
            if (! is_string($value) || trim($value) === '') {
                $missing[] = $locale.'/'.$key;
            }
        }
    }

    expect($missing)->toBe([], implode("\n  ", [
        'The banner names these keys and their locale carries no line for them:',
        ...$missing,
        '',
        'Lang::get returns the key itself when nothing answers to it, so the banner',
        'prints "core::alerts.messages.x" to that reader — at critical severity, in',
        'place of the sentence telling them their tokens may be unredacted.',
    ]));
});
