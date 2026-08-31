<?php

declare(strict_types=1);

namespace Modules\Core\Public\Scheduling;

use Illuminate\Console\Scheduling\Event;

/**
 * @link ../../../../.docs/features/mobile/architecture.md#the-phone-runs-an-artisan-name-on-an-interval-and-nothing-else
 */
final class MobileBackgroundSchedule
{
    // Android WorkManager and iOS BGTaskScheduler both take a repeat period,
    // never a wall clock, so this list is the runner's whole vocabulary:
    // every other expression — every `dailyAt()` in the tree — is dropped
    // from the manifest without failing anything.
    /** @var list<string> */
    public const array RUNNER_INTERVALS = [
        '* * * * *',
        '*/5 * * * *',
        '*/10 * * * *',
        '*/15 * * * *',
        '*/20 * * * *',
        '*/30 * * * *',
        '0 * * * *',
        '0 */2 * * *',
        '0 */3 * * *',
        '0 */4 * * *',
        '0 */6 * * *',
        '0 0 * * *',
    ];

    // A phone can be the only device a household owns, so everything here is
    // work with no other device to fall back on. Each has to be a real artisan
    // command: the runner re-launches the app and invokes one by name, and a
    // `Schedule::call()` closure has no name to invoke.
    /** @return array<string, string> schedule name => artisan command */
    public static function requiredOnDevice(): array
    {
        return [
            'db.backup-daily' => 'db:backup --force',
            'fx.daily-refresh' => 'fx:refresh-rates',
            'recurring.detect' => 'recurring:detect',
            'forecasting.daily-sweep' => 'forecasting:project',
            'counterparties.gc' => 'counterparties:collect-garbage',
            'notifications.prune' => 'notifications:prune',
            'notifications.daily-triggers' => 'notifications:daily-triggers',
            'notifications.budget-nudges' => 'budgets:emit-nudges',
            'drift-alerts.revive-snoozes' => 'drift-alerts:revive-snoozes',
            'anomaly.revive-snoozes' => 'anomaly:revive-snoozes',
            'anomaly.safety-net-sweep' => 'anomaly:safety-net-sweep',
            'open-banking.daily-sync' => 'open-banking:sync-due',
            // Not "no other device to fall back on" but "the phone offers
            // the switch". Auto-import can be turned off on a device, and a
            // promise a screen makes there has to be one that device keeps.
            'receipts.scan-drop-folder' => 'receipts:scan-drop-folder',
        ];
    }

    // Declared in Modules/Mobile/Routes/console.php behind the `onAnyNetwork`
    // macro, so it exists only where nativephp/mobile-background-tasks is
    // installed — the mobile composer root and the device, never the desktop.
    /** @return array<string, string> schedule name => artisan command */
    public static function mobileRootOnly(): array
    {
        return [
            'mobile.queue-drain' => 'queue:work --stop-when-empty --max-time=55 --quiet',
        ];
    }

    // Not "the phone does not need this" but "no schedule on a phone can ever
    // complete it". Stated for the same reason desktopOnly() is: an absence
    // that nothing explains is indistinguishable from a task dropped by
    // accident.
    /** @return array<string, string> schedule name => why no phone schedule can run it */
    public static function impossibleOnDevice(): array
    {
        return [
            'mobile.sync-pull' => 'The bounded sync burst needs the device identity, sealed under the app-lock key, which lives only in an unlocked session. Android WorkManager and iOS BGTaskScheduler start a process that builds its own empty session, so the identity never opens: measured on a paired, fully synced SM-S928B as six firings and no syncs. Syncing runs while the app is open, and the /sync screen says so.',
        ];
    }

    public static function mobileRootLoaded(): bool
    {
        return Event::hasMacro('onAnyNetwork');
    }

    // Stated rather than left to inference: a task absent from the manifest is
    // otherwise indistinguishable from one dropped by accident, which is how
    // twenty of them went missing without anybody noticing.
    /** @return array<string, string> schedule name => why the phone does not run it */
    public static function desktopOnly(): array
    {
        return [
            'desktop.email-scan.timer' => 'Substitutes for the IMAP-idle daemon the desktop shell cannot host; email-scan.incremental is the same work at a slower cadence.',
            'email-scan.incremental' => 'Fetches whole .eml bodies over IMAP against credentials and an OAuth client provisioned through a desktop browser flow; the phone receives the results over sync.',
            'email-scan.discovery' => 'Same inbox pipeline as email-scan.incremental.',
            'email-scan.detect-ics-statement-ready' => 'Reads inbox_messages rows the inbox pipeline writes, so it has nothing to read on a device that never fetches.',
            'receipts.process-fetched-inbox-messages' => 'Consumes inbox_messages rows the inbox pipeline writes, so it has nothing to read on a device that never fetches.',
        ];
    }

    /**
     * @param  iterable<Event>  $events
     * @return list<string> the artisan commands a manifest built from $events would carry
     */
    public static function carriedBy(iterable $events): array
    {
        $carried = [];

        foreach ($events as $event) {
            $command = self::commandNameOf($event->command);

            if ($command === null || ! in_array($event->expression, self::RUNNER_INTERVALS, true)) {
                continue;
            }

            $carried[] = $command;
        }

        return array_values(array_unique($carried));
    }

    /** @return string|null the artisan name inside a built command string, null for a closure event */
    public static function commandNameOf(?string $command): ?string
    {
        if ($command === null || $command === '') {
            return null;
        }

        return preg_match("/['\"]?artisan['\"]?\s+(.+)/", $command, $matches) === 1
            ? trim($matches[1])
            : null;
    }
}
