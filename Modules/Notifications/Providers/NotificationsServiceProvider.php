<?php

declare(strict_types=1);

namespace Modules\Notifications\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\View\Factory as ViewFactoryContract;
use Illuminate\Contracts\View\View;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\LoadsModuleResources;
use Modules\Core\Public\Support\RegistersScheduledCommands;
use Modules\Notifications\Internal\Console\EmitBudgetNudgesCommand;
use Modules\Notifications\Internal\Console\EmitDailyNotificationTriggersCommand;
use Modules\Notifications\Internal\Console\PruneNotificationsCommand;
use Modules\Notifications\Internal\Delivery\NoSystemNotificationConsent;
use Modules\Notifications\Internal\Delivery\NoSystemNotificationGrantState;
use Modules\Notifications\Internal\Http\Livewire\NotificationsPage;
use Modules\Notifications\Internal\StateMachines\NotificationStateMachine;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Internal\Support\NotificationCopyRenderer;
use Modules\Notifications\Internal\Support\NotificationWriter;
use Modules\Notifications\Public\Contracts\SystemNotificationConsent;
use Modules\Notifications\Public\Contracts\SystemNotificationGrantState;
use Modules\Notifications\Public\Http\Livewire\NotificationsSettingsSection;
use Modules\Notifications\Public\Services\NotificationQuery;
use Modules\Notifications\Public\Services\SuppressionEvaluator;

final class NotificationsServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;
    use RegistersScheduledCommands;

    private const string LISTENER_PERSIST_PAYMENT_REMINDER = 'Modules\Notifications\Internal\Listeners\PersistPaymentReminder';

    private const string EVENT_PAYMENT_REMINDER_DUE = 'Modules\Recurring\Public\Events\PaymentReminderDue';

    private const string LISTENER_RESOLVE_SETTLED_REMINDER = 'Modules\Notifications\Internal\Listeners\ResolveSettledReminder';

    private const string EVENT_PAYMENT_SETTLED = 'Modules\Recurring\Public\Events\PaymentSettled';

    private const string LISTENER_PERSIST_BUDGET_NUDGE = 'Modules\Notifications\Internal\Listeners\PersistBudgetNudge';

    private const string EVENT_BUDGET_THRESHOLD_CROSSED = 'Modules\Budgets\Public\Events\BudgetThresholdCrossed';

    private const string LISTENER_PERSIST_SAVINGS_PROMPT = 'Modules\Notifications\Internal\Listeners\PersistSavingsPrompt';

    private const string EVENT_SAVINGS_PROMPT_DUE = 'Modules\DriftAlerts\Public\Events\SavingsPromptDue';

    private const string LISTENER_PERSIST_POSITION_DIGEST = 'Modules\Notifications\Internal\Listeners\PersistPositionDigest';

    private const string EVENT_POSITION_DIGEST_DUE = 'Modules\Position\Public\Events\PositionDigestDue';

    private const string LISTENER_PERSIST_COALESCED_IMPORT = 'Modules\Notifications\Internal\Listeners\PersistCoalescedImport';

    private const string EVENT_TRANSACTION_BATCH_IMPORTED = 'Modules\Ledger\Public\Events\TransactionBatchImported';

    private const string LISTENER_PERSIST_DRIFT_ALERT = 'Modules\Notifications\Internal\Listeners\PersistDriftAlert';

    private const string EVENT_DRIFT_ALERT_OPENED = 'Modules\DriftAlerts\Public\Events\DriftAlertOpened';

    private const string LISTENER_PERSIST_FORECAST_SHORTFALL = 'Modules\Notifications\Internal\Listeners\PersistForecastShortfall';

    private const string EVENT_FORECAST_SHORTFALL_DETECTED = 'Modules\Forecasting\Public\Events\ForecastShortfallDetected';

    private const string LISTENER_PERSIST_ICS_STATEMENT_READY = 'Modules\Notifications\Internal\Listeners\PersistIcsStatementReady';

    private const string EVENT_ICS_STATEMENT_READY = 'Modules\EmailScan\Public\Events\IcsStatementReady';

    public function register(): void
    {
        // Rebound by Mobile on a real device, where the OS gates delivery
        // behind a runtime grant the app has to ask for.
        $this->app->singleton(SystemNotificationConsent::class, NoSystemNotificationConsent::class);

        // Rebound beside it, and for the same platforms: the read half, so a
        // settings screen and a delivery record can both say whether the OS
        // is showing what this app posts.
        $this->app->singleton(SystemNotificationGrantState::class, NoSystemNotificationGrantState::class);
        $this->app->singleton(NotificationStateMachine::class);
        $this->app->singleton(DeterministicKeyDeriver::class);
        $this->app->singleton(SuppressionEvaluator::class);
        $this->app->singleton(NotificationWriter::class);
        $this->app->singleton(NotificationCopyRenderer::class);
    }

    public function boot(Dispatcher $events, LivewireManager $livewire): void
    {
        $this->loadModuleResources('notifications');

        $this->registerScheduledCommands([
            EmitBudgetNudgesCommand::class,
            EmitDailyNotificationTriggersCommand::class,
            PruneNotificationsCommand::class,
        ]);

        $livewire->component('notifications.page', NotificationsPage::class);
        $livewire->component('notifications.settings-section', NotificationsSettingsSection::class);

        $this->registerNavBadgeComposer();

        $this->registerTriggerListeners($events);
    }

    // Counted per render, not memoised for the boot: the drawer holds the one
    // sidebar mount in the app, so a memo collapsed nothing and instead froze
    // the badge for any second render — which is exactly what the rail's
    // recount is.
    private function registerNavBadgeComposer(): void
    {
        $app = $this->app;
        $factory = $app->make(ViewFactoryContract::class);

        $factory->composer('shell::livewire.app-sidebar', static function (View $compose) use ($app): void {
            $currentUser = $app->make(CurrentUser::class);

            /** @var array<string, int> $navCounts */
            $navCounts = (array) ($compose->getData()['navCounts'] ?? []);

            if (! $currentUser->isAuthenticated()) {
                $navCounts['notifications'] = 0;
                $compose->with('navCounts', $navCounts);

                return;
            }

            $query = $app->make(NotificationQuery::class);
            $navCounts['notifications'] = $query->unreadCountForUser($currentUser->user());
            $compose->with('navCounts', $navCounts);
        });
    }

    // A listener or trigger event class may be absent, and an unguarded
    // reference would fatal the boot.
    private function registerTriggerListeners(Dispatcher $events): void
    {
        foreach (self::triggerBindings() as [$event, $listener]) {
            if (class_exists($listener) && class_exists($event)) {
                $events->listen($event, [$listener, 'handle']);
            }
        }
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function triggerBindings(): array
    {
        return [
            [self::EVENT_PAYMENT_REMINDER_DUE, self::LISTENER_PERSIST_PAYMENT_REMINDER],
            [self::EVENT_PAYMENT_SETTLED, self::LISTENER_RESOLVE_SETTLED_REMINDER],
            [self::EVENT_BUDGET_THRESHOLD_CROSSED, self::LISTENER_PERSIST_BUDGET_NUDGE],
            [self::EVENT_SAVINGS_PROMPT_DUE, self::LISTENER_PERSIST_SAVINGS_PROMPT],
            [self::EVENT_POSITION_DIGEST_DUE, self::LISTENER_PERSIST_POSITION_DIGEST],
            [self::EVENT_TRANSACTION_BATCH_IMPORTED, self::LISTENER_PERSIST_COALESCED_IMPORT],
            [self::EVENT_DRIFT_ALERT_OPENED, self::LISTENER_PERSIST_DRIFT_ALERT],
            [self::EVENT_FORECAST_SHORTFALL_DETECTED, self::LISTENER_PERSIST_FORECAST_SHORTFALL],
            [self::EVENT_ICS_STATEMENT_READY, self::LISTENER_PERSIST_ICS_STATEMENT_READY],
        ];
    }
}
