<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// Laravel resolves `errors::{status}` before `errors::4xx`, and it ships error
// views of its own -- 401, 402, 403, 429 -- inside the same `errors::`
// namespace. So the generic 4xx page this repo added never fired for any of
// them: a 403 rendered the framework's "Forbidden", in English, whatever the
// reader had chosen, and under a dev build's APP_DEBUG that page is a stack
// trace over this app's own source with no navigation off it.
//
// Measured before the fix: a 403 raised inside a route answered `lang="en"`
// while the locale bound on the application was `nl`, and the body carried no
// `bx-error` at all. The 404 beside it answered `nl`.
//
// This rule is against the framework's directory rather than a list written
// here, so an upgrade that ships a new status is caught the day it lands.

function frameworkErrorStatuses(): array
{
    $directory = base_path('vendor/laravel/framework/src/Illuminate/Foundation/Exceptions/views');

    if (! is_dir($directory)) {
        return [];
    }

    $statuses = [];
    foreach ((array) scandir($directory) as $entry) {
        if (! is_string($entry)) {
            continue;
        }

        // Through the checked seam: a scan that stopped part-way returns the
        // same empty answer as one that found nothing.
        $match = PatternScan::first('/^(\d{3})\.blade\.php$/', $entry);

        if ($match !== []) {
            $statuses[] = $match[1];
        }
    }

    sort($statuses);

    return $statuses;
}

it('finds the framework views this rule is about, so it cannot pass by reading nothing', function (): void {
    expect(frameworkErrorStatuses())->not->toBeEmpty()
        ->and(frameworkErrorStatuses())->toContain('403');
});

it('draws every status the framework would otherwise draw for us', function (): void {
    $missing = [];

    foreach (frameworkErrorStatuses() as $status) {
        if (! is_file(base_path(sprintf('resources/views/errors/%s.blade.php', $status)))) {
            $missing[] = $status;
        }
    }

    expect($missing)->toBe([], 'The framework draws these, so this app does not: '.implode(', ', $missing));
});

it('draws them through this app’s own shell rather than restating a page', function (): void {
    $outside = [];

    foreach (frameworkErrorStatuses() as $status) {
        $path = base_path(sprintf('resources/views/errors/%s.blade.php', $status));

        if (! is_file($path) || ! str_contains((string) file_get_contents($path), '<x-errors.beatrax-error')) {
            $outside[] = $status;
        }
    }

    // Collected rather than asserted per file: toContain takes needles, and a
    // trailing explanation becomes one more string the file must carry.
    expect($outside)->toBe([], 'These do not go through the app’s error shell: '.implode(', ', $outside));
});
