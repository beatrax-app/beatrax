<?php

declare(strict_types=1);

require_once __DIR__.'/nativephp_scaffold_root.php';

/*
 * Make the background-task manifest incapable of naming one identifier twice.
 *
 * On iOS a duplicate is not a warning. `BGTaskScheduler.register` throws
 * NSInternalInconsistencyException the second time a handler is registered for
 * one identifier, PHPScheduler.registerHandlers() does not catch it, and it is
 * raised from init before the first frame — so the app terminates on signal 6
 * on every launch, with no screen and no log of its own. A shipped build did
 * exactly that: 26 identifiers of which 12 were doubled, and
 * `com.nativephp.task.recurring-detect` — merely the first of the twelve —
 * named in the abort message.
 *
 * Android is the reason nobody saw it. WorkManager enqueues by unique name and
 * replaces, so the same duplicates registered and ran there without a mark.
 * That asymmetry is why the guard belongs in the generator rather than only in
 * the schedule: one platform forgives this class of mistake completely and the
 * other treats it as fatal, so the writer of a schedule gets no feedback at all
 * until an iPhone refuses to open.
 *
 * The schedule itself no longer contains duplicates — routes/console.php is
 * idempotent through Modules\Core\Public\Scheduling\ScheduleRegistrationGuard,
 * and Modules/Mobile/tests/Unit/IosBackgroundTaskManifestTest asserts the
 * generated list is unique from a fresh console process. This is the second
 * layer: whatever the schedule holds, the manifest carries each identifier once.
 *
 * Why a patch script rather than a binding: both call sites construct the
 * generator with `new SchedulerManifestGenerator` — BackgroundTasksServiceProvider
 * ::registerScheduledTasks() and BackgroundTasksPreCompileCommand::handle() — so
 * no container override can reach it, and a subclass has nothing to bind to.
 * `composer install` restores the vendor tree, which is what every other script
 * in this directory exists to survive; NativeBuildPatches re-runs the whole set
 * on CommandStarting for native:run / native:build / native:package, and the
 * pre-compile hook that writes the identifiers is an in-process Artisan::call
 * later in that same command, so the class autoloads from the patched file.
 *
 * Idempotent, and a missing anchor is a hard failure rather than a silent skip.
 */

$target = beatraxMobileVendorPath('nativephp/mobile-background-tasks/src/SchedulerManifestGenerator.php');

if ($target === null) {
    fwrite(STDOUT, "nativephp_dedupe_background_task_identifiers: mobile-background-tasks is not installed — skipping.\n");

    exit(0);
}

$source = (string) file_get_contents($target);

if (str_contains($source, 'nativephp_dedupe_background_task_identifiers')) {
    fwrite(STDOUT, "nativephp_dedupe_background_task_identifiers: already patched.\n");

    exit(0);
}

$anchor = <<<'PHP'
            $tasks[] = [
                'command' => $command,
                'identifier' => self::identifierFor($command),
                'interval_minutes' => $intervalMinutes,
                'constraints' => $constraints,
                'long_running' => $event->nativeLongRunning ?? false,
            ];
        }

        return $tasks;
PHP;

$replacement = <<<'PHP'
            // Patched by scripts/nativephp_dedupe_background_task_identifiers.php
            // — iOS BGTaskScheduler.register aborts the app on the second
            // handler for one identifier, so a duplicate here is a phone that
            // never opens rather than a task that runs twice.
            $identifier = self::identifierFor($command);

            if (isset($tasks[$identifier])) {
                \Log::info("SchedulerManifest: skipped $command — $identifier is already in the manifest");

                continue;
            }

            $tasks[$identifier] = [
                'command' => $command,
                'identifier' => $identifier,
                'interval_minutes' => $intervalMinutes,
                'constraints' => $constraints,
                'long_running' => $event->nativeLongRunning ?? false,
            ];
        }

        return array_values($tasks);
PHP;

if (! str_contains($source, $anchor)) {
    fwrite(STDERR, "nativephp_dedupe_background_task_identifiers: manifest-building anchor not found in {$target}.\n");
    fwrite(STDERR, "The package changed how it builds the task list; re-check that it cannot emit one identifier twice before shipping an iOS build.\n");

    exit(1);
}

if (file_put_contents($target, str_replace($anchor, $replacement, $source)) === false) {
    fwrite(STDERR, "nativephp_dedupe_background_task_identifiers: could not write {$target}.\n");

    exit(1);
}

fwrite(STDOUT, "nativephp_dedupe_background_task_identifiers: patched SchedulerManifestGenerator::generate().\n");

exit(0);
