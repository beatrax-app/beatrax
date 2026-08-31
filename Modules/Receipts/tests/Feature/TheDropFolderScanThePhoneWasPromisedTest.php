<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Modules\Core\Models\User;
use Modules\Core\Public\Scheduling\MobileBackgroundSchedule;
use Modules\Core\Public\Support\RegistersScheduledCommands;
use Modules\Receipts\Internal\Jobs\ScanInboxDropFolderJob;
use Modules\Receipts\Providers\ReceiptsServiceProvider;

// Read on an iPhone: "Beatrax scans storage/app/inbox-drop/1/ every 5 minutes".
// The toggle that turns it on is enabled there, and the scan was a
// `Schedule::call()` closure — `$event->command` null, dropped from the
// device's background manifest without failing anything, and dispatched from
// nowhere else. The phone promised a scan no phone could reach.

function dfsScheduledScan(): ?ScheduledEvent
{
    foreach (app(Schedule::class)->events() as $event) {
        /** @var ScheduledEvent $event */
        if ((string) $event->description === 'receipts.scan-drop-folder') {
            return $event;
        }
    }

    return null;
}

function dfsReader(string $username, bool $dropFolderOn): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'auto_import_drop_folder' => $dropFolderOn,
    ]);
}

it('gives the drop-folder scan the artisan name the phone manifest reads, not a closure', function (): void {
    $event = dfsScheduledScan();

    expect($event)->not->toBeNull('Nothing schedules receipts.scan-drop-folder at all.');
    expect($event->command)->not->toBeNull(
        'SchedulerManifestGenerator::generate() reads $event->command and skips the entry when it is null. '
        .'A Schedule::call() closure is null there, so the scan the settings copy promises never enters '
        ."the device's BGTaskScheduler / WorkManager manifest.",
    );
    expect(MobileBackgroundSchedule::commandNameOf($event->command))->toBe('receipts:scan-drop-folder');
    expect($event->expression)->toBe('*/5 * * * *');
    expect($event->mutexName())->not->toBe('');
});

it('carries the drop-folder scan into a manifest built from the live schedule', function (): void {
    expect(MobileBackgroundSchedule::carriedBy(app(Schedule::class)->events()))
        ->toContain('receipts:scan-drop-folder');
});

it('declares the drop-folder scan as work the phone runs, not as a desktop-only decision', function (): void {
    expect(MobileBackgroundSchedule::requiredOnDevice())->toHaveKey('receipts.scan-drop-folder');
    expect(MobileBackgroundSchedule::desktopOnly())->not->toHaveKey('receipts.scan-drop-folder');
});

// APP_RUNNING_IN_CONSOLE is false on the background runner's hot path, so a
// command registered behind runningInConsole() is missing from the Artisan
// application the runner calls into and the task dies command-not-found.
it('registers the command outside any console guard', function (): void {
    expect(class_uses_recursive(ReceiptsServiceProvider::class))
        ->toContain(RegistersScheduledCommands::class);

    expect((string) file_get_contents(base_path('Modules/Receipts/Providers/ReceiptsServiceProvider.php')))
        ->not->toContain('runningInConsole');
});

it('dispatches a scan for every reader who turned the drop folder on, and for nobody else', function (): void {
    Bus::fake();

    $on = dfsReader('dfs-on', dropFolderOn: true);
    $off = dfsReader('dfs-off', dropFolderOn: false);

    Artisan::call('receipts:scan-drop-folder');

    Bus::assertDispatched(
        ScanInboxDropFolderJob::class,
        static fn (ScanInboxDropFolderJob $job): bool => $job->userId === $on->id,
    );
    Bus::assertNotDispatched(
        ScanInboxDropFolderJob::class,
        static fn (ScanInboxDropFolderJob $job): bool => $job->userId === $off->id,
    );
});
