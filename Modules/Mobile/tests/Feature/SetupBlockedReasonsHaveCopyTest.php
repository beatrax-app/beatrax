<?php

declare(strict_types=1);

use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Sync\SetupStep;
use Modules\Mobile\Internal\Sync\SyncBlockedReason;

/*
 * The setup screen renders the reason by key. A case without copy would print
 * the raw key at the user — on the one screen whose whole job is to explain
 * what the device is waiting for. Walking cases() rather than a hand-kept
 * list is the point: the list silently missed a case added beside it.
 */

it('has copy for every blocked reason in both languages', function (): void {
    $missing = [];

    foreach (['en', 'nl'] as $locale) {
        app('translator')->setLocale($locale);

        foreach (SyncBlockedReason::cases() as $reason) {
            $key = 'mobile::setup.blocked.'.$reason->value;

            if (Lang::get($key) === $key) {
                $missing[] = "{$locale}: {$reason->value}";
            }
        }
    }

    expect($missing)->toBe([], implode(', ', $missing));
});

it('has a name and a working line for every setup step in both languages', function (): void {
    $missing = [];

    foreach (['en', 'nl'] as $locale) {
        app('translator')->setLocale($locale);

        foreach (SetupStep::cases() as $step) {
            foreach (['step', 'working'] as $group) {
                $key = "mobile::setup.{$group}.{$step->value}";

                if (Lang::get($key) === $key) {
                    $missing[] = "{$locale}: {$key}";
                }
            }
        }
    }

    expect($missing)->toBe([], implode(', ', $missing));
});
