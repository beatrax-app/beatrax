<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Console;

use Illuminate\Console\Command;
use Modules\Core\Models\User;
use Modules\Core\Public\Scheduling\DailyLocalWindow;
use Modules\DriftAlerts\Public\Services\SavingsPromptDispatch;
use Modules\Notifications\Internal\Enums\DeferredNotificationPass;
use Modules\Notifications\Internal\Support\DeferredNotificationPasses;
use Modules\Notifications\Public\Services\NotificationPreferenceQuery;
use Modules\Position\Public\Services\PositionDigestDispatch;
use Modules\Recurring\Public\Services\PaymentReminderDispatch;
use Psr\Log\LoggerInterface;
use Throwable;

// The one daily user-notification pass: payment reminders, position digest and
// savings prompts, which always shared the 09:15 slot and now share the entry
// that survives a phone, where the runner can only be given an interval.
/**
 * @link ../../../../.docs/features/mobile/architecture.md#a-wall-clock-hour-the-runner-cannot-express-moves-into-the-command
 */
final class EmitDailyNotificationTriggersCommand extends Command
{
    public const string WINDOW_KEY = 'notifications.daily-triggers';

    // Deliberately after the FX refresh, so any converted figure the digest
    // shows uses a rate fetched earlier the same day rather than yesterday's.
    public const string LOCAL_TIME = '09:15';

    /** @var string */
    protected $signature = 'notifications:daily-triggers';

    /** @var string */
    protected $description = 'Emit the daily payment reminders, position digest and savings prompts. Runs once per local day, at or after '.self::LOCAL_TIME.'; any other run exits without emitting.';

    public function __construct(
        private readonly NotificationPreferenceQuery $preferences,
        private readonly PaymentReminderDispatch $reminders,
        private readonly PositionDigestDispatch $digest,
        private readonly SavingsPromptDispatch $savingsPrompts,
        private readonly DailyLocalWindow $window,
        private readonly DeferredNotificationPasses $deferred,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->window->claim(self::WINDOW_KEY, self::LOCAL_TIME)) {
            // A hand-run before the window, or a second one the same day, is
            // otherwise silence indistinguishable from a pass that emitted
            // nothing because there was nothing to send.
            $this->info(sprintf('Nothing emitted: this pass runs once per local day, at or after %s.', self::LOCAL_TIME));

            return self::SUCCESS;
        }

        User::query()->lazyById(100)->each(function (User $user): void {
            // All three triggers write nothing but notification content, so a
            // process that cannot seal has no partial work to do here. The
            // window claim above is left consumed on purpose: the pass this
            // records is replayed per user, not by re-running the command.
            if ($this->deferred->deferIfKeyless($user->id, DeferredNotificationPass::DailyTriggers)) {
                return;
            }

            $preferences = $this->preferences->forCurrentDevice($user);

            $this->attempt('reminders', fn () => $this->reminders->forUser($user->id, $preferences->reminderLeadDays));
            $this->attempt('digest', fn () => $this->digest->forUser($user->id, $preferences->digestCadence));
            $this->attempt('savings-prompts', fn () => $this->savingsPrompts->forUser($user->id));
        });

        return self::SUCCESS;
    }

    // Three separate schedule entries used to mean one trigger throwing never
    // stopped the other two. They are one entry now, so the isolation the
    // scheduler used to provide has to be provided here instead.
    /** @param  callable(): void  $dispatch */
    private function attempt(string $trigger, callable $dispatch): void
    {
        try {
            $dispatch();
        } catch (Throwable $e) {
            $this->logger->warning('notifications:daily-triggers: one trigger failed to dispatch.', [
                'trigger' => $trigger,
                'exception' => $e,
            ]);
        }
    }
}
