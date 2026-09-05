<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Modules\Core\Public\Scheduling\MobileBackgroundSchedule;
use NativePHP\BackgroundTasks\SchedulerManifestGenerator;
use Symfony\Component\Process\Process;

// BGTaskScheduler is handed its whole task list once, from
// BackgroundTasksServiceProvider::boot(). Every schedule the app wants on the
// phone has to exist by then, and provider boot order is what decides that —
// which no in-process assertion can observe, because by the time a test runs
// the application is fully booted and the ordering is gone.
function mobileBackgroundTaskRoot(): ?string
{
    foreach ([base_path('mobile-app'), base_path()] as $root) {
        if (is_dir($root.'/vendor/nativephp/mobile-background-tasks') && is_file($root.'/.env')) {
            return $root;
        }
    }

    return null;
}

/** @return array{ok: bool, output: string} */
function mobileBackgroundTaskManifestAtBootBoundary(string $root): array
{
    $script = sys_get_temp_dir().'/beatrax-bgtask-'.bin2hex(random_bytes(6)).'.php';

    // The nested booting callback is the measurement. Laravel fires every
    // booting callback before the first provider boots, and appends to that
    // queue mid-sweep, so a callback registered from inside one lands after
    // every callback the providers queued and still ahead of provider boot.
    file_put_contents($script, <<<PHP
        <?php
        require '{$root}/vendor/autoload.php';
        \$app = require '{$root}/bootstrap/app.php';
        \$app->instance('request', Illuminate\\Http\\Request::create('/', 'GET'));
        \$seen = [];
        \$app->booting(function (\$app) use (&\$seen) {
            \$app->booting(function () use (&\$seen) {
                \$seen = (new NativePHP\\BackgroundTasks\\SchedulerManifestGenerator)->generate();
            });
        });
        \$app->make(Illuminate\\Contracts\\Http\\Kernel::class)
            ->handle(Illuminate\\Http\\Request::create('/', 'GET'));
        echo json_encode(array_column(\$seen, 'command'));
        PHP);

    $process = new Process([PHP_BINARY, $script]);
    $process->setTimeout(120);
    $process->run();

    @unlink($script);

    return [
        'ok' => $process->isSuccessful(),
        'output' => trim($process->getOutput()).trim($process->getErrorOutput()),
    ];
}

/** @return array{ok: bool, output: string} */
function mobileBackgroundTaskIdentifiersAtBuildTime(string $root): array
{
    $script = sys_get_temp_dir().'/beatrax-bgids-'.bin2hex(random_bytes(6)).'.php';

    // The console kernel, not the HTTP one. `native:background-tasks:pre-compile`
    // writes the shipped identifier list from an artisan process, and
    // Kernel::discoverCommands() — which runs on that path and no other — is the
    // second loader of routes/console.php. Booting over HTTP cannot see it.
    file_put_contents($script, <<<PHP
        <?php
        require '{$root}/vendor/autoload.php';
        \$app = require '{$root}/bootstrap/app.php';
        \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
        echo 'IDENTIFIERS:'.json_encode(
            (new NativePHP\\BackgroundTasks\\SchedulerManifestGenerator)->generateIdentifiers()
        );
        PHP);

    $process = new Process([PHP_BINARY, $script]);
    $process->setTimeout(120);
    $process->run();

    @unlink($script);

    return [
        'ok' => $process->isSuccessful(),
        'output' => trim($process->getOutput()).trim($process->getErrorOutput()),
    ];
}

/** @return list<string> */
function mobileBackgroundTaskIdentifiersFrom(string $output): array
{
    $marker = strrpos($output, 'IDENTIFIERS:');

    expect($marker)->not->toBeFalse("the identifier probe printed nothing usable:\n".$output);

    /** @var list<string> $identifiers */
    $identifiers = json_decode(substr($output, (int) $marker + strlen('IDENTIFIERS:')), true);

    return $identifiers;
}

// iOS BGTaskScheduler.register throws NSInternalInconsistencyException the second
// time one identifier is registered, uncaught, before the first frame — where
// Android WorkManager replaces by unique name and never notices. A build shipped
// twenty-six identifiers with twelve doubled and the app aborted on every launch.
it('generates each iOS background-task identifier exactly once', function (): void {
    $root = mobileBackgroundTaskRoot();

    if ($root === null) {
        expect(true)->toBeTrue();

        return;
    }

    $result = mobileBackgroundTaskIdentifiersAtBuildTime($root);

    expect($result['ok'])->toBeTrue("the mobile shell failed to boot:\n".$result['output']);

    $identifiers = mobileBackgroundTaskIdentifiersFrom($result['output']);

    expect($identifiers)->not->toBeEmpty();

    $duplicated = array_keys(array_filter(
        array_count_values($identifiers),
        static fn (int $times): bool => $times > 1,
    ));

    expect($duplicated)->toBe([], implode("\n", [
        'These identifiers reach Info.plist and background_task_identifiers.json more than once:',
        '  '.implode("\n  ", $duplicated),
        '',
        'BGTaskScheduler.register aborts the app on the second handler for one identifier, so',
        'every name here is a launch that never completes on iOS.',
    ]));
});

it('has every mobile-root entry in the background-task manifest before any provider boots', function (): void {
    $root = mobileBackgroundTaskRoot();

    if ($root === null) {
        expect(true)->toBeTrue();

        return;
    }

    $result = mobileBackgroundTaskManifestAtBootBoundary($root);

    expect($result['ok'])->toBeTrue("the mobile shell failed to boot:\n".$result['output']);

    // A fresh process is the only place these can be measured:
    // MobileServiceProvider `require_once`s its console routes, so a second
    // application in the same process registers none of them.
    foreach (MobileBackgroundSchedule::mobileRootOnly() as $name => $command) {
        expect(str_contains($result['output'], $command))->toBeTrue(
            "Nothing registered {$name} ({$command}) by the time BackgroundTasksServiceProvider::boot() "
            ."reads the schedule, so the platform was asked to run it never. Saw: {$result['output']}",
        );
    }
});

// Two filters drop a schedule without failing anything, and neither is visible
// from the phone, so the named commands that do NOT survive the trip are pinned
// here. It is empty now: `db:backup --force` sat in it at `dailyAt('03:00')` —
// a schedule somebody wrote that neither platform ever ran — and every task the
// phone must run is a Schedule::command() on an expression the runner has an
// interval for. TheBackgroundManifestCarriesEveryTaskThePhoneMustRunTest asserts
// the other direction: that the ones which must survive, do.
/** @var list<string> */
const IOS_MANIFEST_CANNOT_CARRY = [];

/** @return list<string> every artisan command the schedule names, deduplicated */
function scheduledCommandNames(): array
{
    $names = [];

    foreach (app(Schedule::class)->events() as $event) {
        if (preg_match("/['\"]?artisan['\"]?\s+(.+)/", (string) $event->command, $m) === 1) {
            $names[] = trim($m[1]);
        }
    }

    return array_values(array_unique($names));
}

it('pins every named schedule the iOS background-task manifest cannot carry', function (): void {
    if (! class_exists(SchedulerManifestGenerator::class)) {
        expect(true)->toBeTrue();

        return;
    }

    $carried = array_column((new SchedulerManifestGenerator)->generate(), 'command');
    $dropped = array_values(array_diff(scheduledCommandNames(), $carried));

    expect($dropped)->toBe(IOS_MANIFEST_CANNOT_CARRY);
});
