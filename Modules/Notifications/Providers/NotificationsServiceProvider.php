<?php

declare(strict_types=1);

namespace Modules\Notifications\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Notifications\Internal\StateMachines\NotificationStateMachine;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Internal\Support\NotificationWriter;
use Modules\Notifications\Public\Actions\DismissNotification;
use Modules\Notifications\Public\Actions\MarkNotificationRead;
use Modules\Notifications\Public\Actions\UndoDismissNotification;
use Modules\Notifications\Public\Services\NotificationPreferenceQuery;
use Modules\Notifications\Public\Services\NotificationQuery;
use Modules\Notifications\Public\Services\SuppressionEvaluator;

/**
 * Wires the Notifications module.
 *
 * Per D-26 the notification store must boot on web, artisan AND mobile —
 * unlike `Modules\Desktop`, this provider is NEVER `class_exists`-guarded
 * for its OWN classes.
 *
 * register() binds every stateless in-tree service as a singleton: the
 * store's sole mutator (`NotificationStateMachine`), the single
 * deterministic key-derivation site (`DeterministicKeyDeriver`), the
 * idempotent writer (`NotificationWriter`), the inbox read model
 * (`NotificationQuery`), and the three lifecycle actions.
 *
 * boot() conditionally loads the module's migrations, routes, and views via
 * `is_dir`/`is_file` guards, and — per this plan's
 * <planner_decisions> — is the SINGLE OWNER of the eight trigger-listener
 * registrations for the whole phase (plans 18-06..18-11 add ONLY their
 * listener classes; none of them may edit this file). Every registration is
 * guarded on BOTH the listener class and the trigger event class existing,
 * each referenced by a runtime-built FQCN string constant (never `::class`
 * on a not-yet-existing class) so PHPStan does not fold the `class_exists()`
 * guard to an impossible type — mirrors `AnomalyServiceProvider`'s guarded-
 * subscription pattern and the runtime-FQCN idiom already established by
 * `BudgetsServiceProvider`/`ReportsServiceProvider`/`MigrationServiceProvider`.
 *
 * Trigger event ownership (D-28 inversion, this plan's <planner_decisions>
 * §1): every trigger event class lives in the TRIGGER module's own
 * `Public\Events` namespace, never in `Modules\Notifications\Public\Events`
 * — `NotificationDeliverable` is the sole exception, because Notifications
 * is its producer.
 */
final class NotificationsServiceProvider extends ServiceProvider
{
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

    public function register(): void
    {
        $this->app->singleton(NotificationStateMachine::class);
        $this->app->singleton(DeterministicKeyDeriver::class);
        $this->app->singleton(NotificationPreferenceQuery::class);
        $this->app->singleton(SuppressionEvaluator::class);
        $this->app->singleton(NotificationWriter::class);
        $this->app->singleton(NotificationQuery::class);
        $this->app->singleton(MarkNotificationRead::class);
        $this->app->singleton(DismissNotification::class);
        $this->app->singleton(UndoDismissNotification::class);
    }

    public function boot(Dispatcher $events, LivewireManager $livewire): void
    {
        if (is_dir(__DIR__.'/../Database/Migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        }
        if (is_file(__DIR__.'/../Routes/web.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        }
        if (is_dir(__DIR__.'/../Resources/views')) {
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'notifications');
        }

        $this->registerTriggerListeners($events);
    }

    /**
     * The eight guarded listener registrations (this plan's
     * <planner_decisions> §2) — none of these listener classes exist yet,
     * and four of the trigger event classes do not either. An unguarded
     * reference would fatal the boot; every pair is checked with
     * `class_exists()` before `Dispatcher::listen()` is ever called.
     */
    private function registerTriggerListeners(Dispatcher $events): void
    {
        if (class_exists(self::LISTENER_PERSIST_PAYMENT_REMINDER) && class_exists(self::EVENT_PAYMENT_REMINDER_DUE)) {
            $events->listen(self::EVENT_PAYMENT_REMINDER_DUE, [self::LISTENER_PERSIST_PAYMENT_REMINDER, 'handle']);
        }

        if (class_exists(self::LISTENER_RESOLVE_SETTLED_REMINDER) && class_exists(self::EVENT_PAYMENT_SETTLED)) {
            $events->listen(self::EVENT_PAYMENT_SETTLED, [self::LISTENER_RESOLVE_SETTLED_REMINDER, 'handle']);
        }

        if (class_exists(self::LISTENER_PERSIST_BUDGET_NUDGE) && class_exists(self::EVENT_BUDGET_THRESHOLD_CROSSED)) {
            $events->listen(self::EVENT_BUDGET_THRESHOLD_CROSSED, [self::LISTENER_PERSIST_BUDGET_NUDGE, 'handle']);
        }

        if (class_exists(self::LISTENER_PERSIST_SAVINGS_PROMPT) && class_exists(self::EVENT_SAVINGS_PROMPT_DUE)) {
            $events->listen(self::EVENT_SAVINGS_PROMPT_DUE, [self::LISTENER_PERSIST_SAVINGS_PROMPT, 'handle']);
        }

        if (class_exists(self::LISTENER_PERSIST_POSITION_DIGEST) && class_exists(self::EVENT_POSITION_DIGEST_DUE)) {
            $events->listen(self::EVENT_POSITION_DIGEST_DUE, [self::LISTENER_PERSIST_POSITION_DIGEST, 'handle']);
        }

        if (class_exists(self::LISTENER_PERSIST_COALESCED_IMPORT) && class_exists(self::EVENT_TRANSACTION_BATCH_IMPORTED)) {
            $events->listen(self::EVENT_TRANSACTION_BATCH_IMPORTED, [self::LISTENER_PERSIST_COALESCED_IMPORT, 'handle']);
        }

        if (class_exists(self::LISTENER_PERSIST_DRIFT_ALERT) && class_exists(self::EVENT_DRIFT_ALERT_OPENED)) {
            $events->listen(self::EVENT_DRIFT_ALERT_OPENED, [self::LISTENER_PERSIST_DRIFT_ALERT, 'handle']);
        }

        if (class_exists(self::LISTENER_PERSIST_FORECAST_SHORTFALL) && class_exists(self::EVENT_FORECAST_SHORTFALL_DETECTED)) {
            $events->listen(self::EVENT_FORECAST_SHORTFALL_DETECTED, [self::LISTENER_PERSIST_FORECAST_SHORTFALL, 'handle']);
        }
    }
}
