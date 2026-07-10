<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Schedule;

/*
 * Mobile module scheduled/console entries.
 *
 * D-07's background leg (RESEARCH.md Pattern 2): register `sync:mobile-pull`
 * with Laravel's own scheduler DSL so the premium `nativephp/mobile-
 * background-tasks` plugin (Plan 03, gated behind a license/supply-chain
 * checkpoint) can translate the entry into a WorkManager (Android) /
 * BGTaskScheduler (iOS) registration once it is installed.
 *
 * `Schedule::command('sync:mobile-pull')` is safe to register right now
 * even though `MobilePullCommand` (Plan 09) does not exist yet — Laravel
 * resolves scheduled commands by STRING NAME at fire time, not at
 * route-registration time, so an unresolvable command name here is inert
 * until Plan 09 ships the class.
 *
 * `onAnyNetwork()` is NOT a vanilla Laravel Scheduling\Event method — it is
 * a macro the mobile-background-tasks plugin adds via Macroable. Calling
 * it before that plugin is installed would fatal with "Call to undefined
 * method". `Event::hasMacro()` guards the whole registration so this file
 * stays inert (and CI-safe) until Plan 03 installs the plugin — the
 * "class_exists guard on the mobile-background-tasks API" this file's
 * owning plan calls for, applied to the actual point of fragility (the
 * macro call), since the plugin publishes no marker class to check
 * against before installation.
 *
 * everyFifteenMinutes() is the Android WorkManager interval FLOOR only —
 * iOS BGTaskScheduler treats it as a best-effort HINT and may delay
 * significantly, especially on a freshly installed app (RESEARCH.md
 * Pitfall 2). Do not read a missed window as a bug.
 */
if (Event::hasMacro('onAnyNetwork')) {
    Schedule::command('sync:mobile-pull')
        ->name('mobile.sync-pull')
        ->everyFifteenMinutes()
        ->onAnyNetwork()
        ->withoutOverlapping(10);
}
