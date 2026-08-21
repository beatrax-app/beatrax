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
use Modules\Notifications\Internal\Http\Livewire\NotificationsPage;
use Modules\Notifications\Internal\StateMachines\NotificationStateMachine;
use Modules\Notifications\Internal\Support\DeepLinkResolver;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Internal\Support\NotificationCopyRenderer;
use Modules\Notifications\Internal\Support\NotificationWriter;
use Modules\Notifications\Public\Actions\DismissNotification;
use Modules\Notifications\Public\Actions\MarkNotificationRead;
use Modules\Notifications\Public\Actions\UndoDismissNotification;
use Modules\Notifications\Public\Http\Livewire\NotificationsSettingsSection;
use Modules\Notifications\Public\Services\NotificationPreferenceQuery;
use Modules\Notifications\Public\Services\NotificationQuery;
use Modules\Notifications\Public\Services\SuppressionEvaluator;

final class NotificationsServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    private const LISTENER_PERSIST_PAYMENT_REMINDER = 'Modules\Notifications\Internal\Listeners\PersistPaymentReminder';

    private const EVENT_PAYMENT_REMINDER_DUE = 'Modules\Recurring\Public\Events\PaymentReminderDue';

    private const LISTENER_RESOLVE_SETTLED_REMINDER = 'Modules\Notifications\Internal\Listeners\ResolveSettledReminder';

    private const EVENT_PAYMENT_SETTLED = 'Modules\Recurring\Public\Events\PaymentSettled';

    private const LISTENER_PERSIST_BUDGET_NUDGE = 'Modules\Notifications\Internal\Listeners\PersistBudgetNudge';

    private const EVENT_BUDGET_THRESHOLD_CROSSED = 'Modules\Budgets\Public\Events\BudgetThresholdCrossed';

    private const LISTENER_PERSIST_SAVINGS_PROMPT = 'Modules\Notifications\Internal\Listeners\PersistSavingsPrompt';

    private const EVENT_SAVINGS_PROMPT_DUE = 'Modules\DriftAlerts\Public\Events\SavingsPromptDue';

    private const LISTENER_PERSIST_POSITION_DIGEST = 'Modules\Notifications\Internal\Listeners\PersistPositionDigest';

    private const EVENT_POSITION_DIGEST_DUE = 'Modules\Position\Public\Events\PositionDigestDue';

    private const LISTENER_PERSIST_COALESCED_IMPORT = 'Modules\Notifications\Internal\Listeners\PersistCoalescedImport';

    private const EVENT_TRANSACTION_BATCH_IMPORTED = 'Modules\Ledger\Public\Events\TransactionBatchImported';

    private const LISTENER_PERSIST_DRIFT_ALERT = 'Modules\Notifications\Internal\Listeners\PersistDriftAlert';

    private const EVENT_DRIFT_ALERT_OPENED = 'Modules\DriftAlerts\Public\Events\DriftAlertOpened';

    private const LISTENER_PERSIST_FORECAST_SHORTFALL = 'Modules\Notifications\Internal\Listeners\PersistForecastShortfall';

    private const EVENT_FORECAST_SHORTFALL_DETECTED = 'Modules\Forecasting\Public\Events\ForecastShortfallDetected';

    private const LISTENER_PERSIST_ICS_STATEMENT_READY = 'Modules\Notifications\Internal\Listeners\PersistIcsStatementReady';

    private const EVENT_ICS_STATEMENT_READY = 'Modules\EmailScan\Public\Events\IcsStatementReady';

    public function register(): void
    {
        $this->app->singleton(NotificationStateMachine::class);
        $this->app->singleton(DeterministicKeyDeriver::class);
        $this->app->singleton(NotificationPreferenceQuery::class);
        $this->app->singleton(SuppressionEvaluator::class);
        $this->app->singleton(NotificationWriter::class);
        $this->app->singleton(NotificationCopyRenderer::class);
        $this->app->singleton(NotificationQuery::class);
        $this->app->singleton(MarkNotificationRead::class);
        $this->app->singleton(DismissNotification::class);
        $this->app->singleton(UndoDismissNotification::class);

        $this->app->singleton(DeepLinkResolver::class);
    }

    public function boot(Dispatcher $events, LivewireManager $livewire): void
    {
        $this->loadModuleResources('notifications');

        $livewire->component('notifications.page', NotificationsPage::class);
        $livewire->component('notifications.settings-section', NotificationsSettingsSection::class);

        $this->registerNavBadgeComposer();

        $this->registerTriggerListeners($events);
    }

    // A boot-scoped per-user memo collapses repeated renders down to one
    // COUNT query.
    private function registerNavBadgeComposer(): void
    {
        $app = $this->app;
        $factory = $app->make(ViewFactoryContract::class);

        /** @var array<int, int> $cache */
        $cache = [];

        $factory->composer('core::livewire.app-sidebar', static function (View $compose) use ($app, &$cache): void {
            $currentUser = $app->make(CurrentUser::class);

            /** @var array<string, int> $navCounts */
            $navCounts = (array) ($compose->getData()['navCounts'] ?? []);

            if (! $currentUser->isAuthenticated()) {
                $navCounts['notifications'] = 0;
                $compose->with('navCounts', $navCounts);

                return;
            }

            $user = $currentUser->user();
            $userId = $user->id;
            if (! array_key_exists($userId, $cache)) {
                $query = $app->make(NotificationQuery::class);
                $cache[$userId] = $query->unreadCountForUser($user);
            }

            $navCounts['notifications'] = $cache[$userId];
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
