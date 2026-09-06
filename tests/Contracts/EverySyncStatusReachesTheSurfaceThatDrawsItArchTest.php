<?php

declare(strict_types=1);

use Modules\Core\Public\Support\Lang;
use Modules\Sync\Public\Enums\SyncOverallStatus;

// The status chain used to end in a bare @else standing for "all synced", so a
// value the chain had no branch for was drawn as the one sentence it must never
// borrow: "All devices up to date". A case added without a branch would inherit
// that again, and the failure is silent — the screen renders, in the wrong words.

/** @return string the surface that draws the aggregate status */
function syncStatusSurface(): string
{
    return (string) file_get_contents(
        base_path('Modules/Sync/Resources/views/livewire/sync-status-section.blade.php'),
    );
}

it('draws every aggregate status the service can return, in a branch of its own', function (): void {
    $blade = syncStatusSurface();

    expect($blade)->not->toBe('', 'The surface that draws the aggregate status is unreadable, so every case below would read as undrawn.');

    // The enum is the denominator, and an empty one would report every case
    // drawn without naming any.
    expect(count(SyncOverallStatus::cases()))->toBeGreaterThan(
        2,
        'The aggregate status enum has almost no case, so this rule held the surface to nothing.'
    );

    $missing = [];
    $shared = [];

    foreach (SyncOverallStatus::cases() as $case) {
        $drawn = substr_count($blade, 'SyncOverallStatus::'.$case->name);

        if ($drawn === 0) {
            $missing[] = $case->name;
        } elseif ($drawn > 1) {
            $shared[] = $case->name.' ('.$drawn.' branches)';
        }
    }

    expect($missing)->toBe([], implode("\n", [
        'These aggregate statuses have no branch on the surface that draws them:',
        ...$missing,
        '',
        'A case with no branch of its own is not a blank line. Before this guard it',
        'fell through to the final @else and was reported as "All devices up to date",',
        'which is the one thing a status the vocabulary cannot name must never say.',
    ]));

    expect($shared)->toBe([], 'A status named by more than one branch is drawn twice or contradicts itself: '
        .implode(', ', $shared));
});

it('gives every aggregate status a line of its own to render', function (): void {
    expect(count(SyncOverallStatus::cases()))->toBeGreaterThan(
        2,
        'The aggregate status enum has almost no case, so this rule checked nothing.'
    );

    $unresolved = [];
    $shared = [];
    $seen = [];

    foreach (SyncOverallStatus::cases() as $case) {
        $key = $case->labelKey();

        if (Lang::get($key) === $key) {
            $unresolved[] = $case->name.' → '.$key;
        }

        if (isset($seen[$key])) {
            $shared[] = $case->name.' and '.$seen[$key].' both render '.$key;
        }

        $seen[$key] = $case->name;
    }

    expect($unresolved)->toBe([], 'A status whose key resolves to itself puts the raw key on screen: '
        .implode(', ', $unresolved));

    expect($shared)->toBe([], 'Two statuses sharing one sentence means one of them is reported as the other: '
        .implode(', ', $shared));
});

// Parity across locales is a separate guard and it compares locales to each
// other, so a key absent from all of them passes it. This asks the other
// question: does every locale that ships carry a line for every status.
it('carries a line for every aggregate status in every locale that ships', function (): void {
    $root = base_path('Modules/Sync/Resources/lang');
    $locales = array_values(array_filter(
        scandir($root) ?: [],
        static fn (string $entry): bool => $entry !== '.' && $entry !== '..' && is_dir($root.'/'.$entry),
    ));

    // 26 languages ship. A scandir that came back short would report the
    // locales it never opened as carrying every line.
    expect(count($locales))->toBeGreaterThan(20, 'Almost no locale directory was found, so a missing line would read as present.');

    $gaps = [];

    foreach ($locales as $locale) {
        /** @var array<string, mixed> $lines */
        $lines = require $root.'/'.$locale.'/status.php';

        foreach (SyncOverallStatus::cases() as $case) {
            $key = str_replace('sync::status.', '', $case->labelKey());
            $line = $lines[$key] ?? null;

            if (! is_string($line) || trim($line) === '') {
                $gaps[] = $locale.' has no line for '.$case->name;
            }
        }
    }

    expect($gaps)->toBe([], implode("\n  ", [
        'These locales carry no line for a status their surface can be asked to draw:',
        ...$gaps,
        '',
        'Parity compares the locales to each other, so a key missing from all of them is',
        'in parity by construction. This asks the other question, and the answer a reader',
        'gets without it is the raw key on the screen that tells them whether their two',
        'devices agree.',
    ]));
});
