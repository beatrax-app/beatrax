<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Budgets\Public\Services\BudgetNudgeDispatch;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\SystemAlertSeverity;
use Modules\Notifications\Internal\Enums\DeferredNotificationPass;
use Modules\Notifications\Internal\Support\DeferredNotificationPasses;
use Modules\Notifications\Tests\Support\BusThatRefusesEveryJob;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(RefreshDatabase::class, EnablesEncryptionForUser::class);

// A deferred pass keeps its mark when it fails, so the next keyed request takes
// it again — which is right, and which is also how a pass that can never finish
// retries forever without anybody being told. The reader's evidence is a
// notification that does not arrive, and a notification that never arrives
// looks exactly like a quiet week.

function passFailureUser(): User
{
    return User::query()->create([
        'username' => 'pass-failure-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function passFailureMark(User $user, DeferredNotificationPass $pass): void
{
    app(DatabaseManager::class)->connection()->table('users')->where('id', $user->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->startOfMonth(),
    ]);

    // Marked directly rather than by running a keyless scheduler: what these
    // cases are about is the run that takes the mark, and a pass with nothing
    // to do still exercises every step of taking one.
    app('cache')->put('beatrax:deferred-notification-pass:'.$user->id.':'.$pass->value, true, 3600);
}

/** @return list<object> */
function passFailureAlerts(User $user): array
{
    return app(DatabaseManager::class)->connection()->table('system_alerts')
        ->where('user_id', $user->id)
        ->where('kind', 'notifications.deferred_pass_failed.'.DeferredNotificationPass::BudgetNudges->value)
        ->orderBy('id')
        ->get()
        ->all();
}

// Only the nudge module's dispatch is given the refusing bus, so the daily
// triggers keep the real one.
function passFailureRefuseNudges(): void
{
    app()->bind(BudgetNudgeDispatch::class, static fn (): BudgetNudgeDispatch => new BudgetNudgeDispatch(new BusThatRefusesEveryJob));
}

function passFailureAllowNudges(): void
{
    app()->forgetInstance(BudgetNudgeDispatch::class);
    app()->bind(BudgetNudgeDispatch::class, static fn (): BudgetNudgeDispatch => new BudgetNudgeDispatch(app(Dispatcher::class)));
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-04 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('raises an alert the reader can see when a pass cannot finish', function (): void {
    $user = passFailureUser();
    /** @var Session $session */
    $session = $this->enablesEncryptionForUser($user);
    passFailureMark($user, DeferredNotificationPass::BudgetNudges);

    passFailureRefuseNudges();

    app(DeferredNotificationPasses::class)->runOutstanding((int) $user->id, $session);

    $alerts = passFailureAlerts($user);

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->severity)->toBe(SystemAlertSeverity::Warning->value)
        ->and($alerts[0]->acknowledged_at)->toBeNull()
        // The column keeps the sentence for a build that cannot resolve the key
        // beside it; the key is what lets a later reader see it in their own
        // language rather than in whichever one the failing request was in.
        ->and((string) $alerts[0]->message)->toContain('budget alerts')
        ->and((string) $alerts[0]->metadata)
        ->toContain('core::alerts.messages.notifications_deferred_pass_failed')
        ->and((string) $alerts[0]->metadata)
        ->toContain('core::alerts.deferred_pass.'.DeferredNotificationPass::BudgetNudges->value);

    // And the mark still stands, so the next keyed request takes it again.
    expect(app(DeferredNotificationPasses::class)->outstandingFor((int) $user->id))
        ->toContain(DeferredNotificationPass::BudgetNudges);
});

it('raises one alert however many requests hit the same failing pass', function (): void {
    $user = passFailureUser();
    /** @var Session $session */
    $session = $this->enablesEncryptionForUser($user);
    passFailureMark($user, DeferredNotificationPass::BudgetNudges);

    passFailureRefuseNudges();

    foreach (range(1, 4) as $ignored) {
        app(DeferredNotificationPasses::class)->runOutstanding((int) $user->id, $session);
    }

    expect(passFailureAlerts($user))->toHaveCount(1);
});

it('takes the alert down itself once the pass gets through', function (): void {
    $user = passFailureUser();
    /** @var Session $session */
    $session = $this->enablesEncryptionForUser($user);
    passFailureMark($user, DeferredNotificationPass::BudgetNudges);

    passFailureRefuseNudges();
    app(DeferredNotificationPasses::class)->runOutstanding((int) $user->id, $session);

    expect(passFailureAlerts($user)[0]->acknowledged_at)->toBeNull();

    passFailureAllowNudges();

    app(DeferredNotificationPasses::class)->runOutstanding((int) $user->id, $session);

    expect(passFailureAlerts($user)[0]->acknowledged_at)->not->toBeNull()
        ->and(app(DeferredNotificationPasses::class)->outstandingFor((int) $user->id))->toBe([]);
});

// The two passes have nothing to do with each other beyond the request that got
// round to both, and one that cannot finish used to take the other down with it.
it('runs the second pass after the first one fails', function (): void {
    $user = passFailureUser();
    /** @var Session $session */
    $session = $this->enablesEncryptionForUser($user);
    passFailureMark($user, DeferredNotificationPass::BudgetNudges);
    passFailureMark($user, DeferredNotificationPass::DailyTriggers);

    passFailureRefuseNudges();

    app(DeferredNotificationPasses::class)->runOutstanding((int) $user->id, $session);

    // The marks are what the next request reads, and they are the only thing
    // that can say the second pass ran after the first threw: its own mark is
    // gone, and the failing one's is still standing.
    expect(app(DeferredNotificationPasses::class)->outstandingFor((int) $user->id))
        ->toBe([DeferredNotificationPass::BudgetNudges]);
});
