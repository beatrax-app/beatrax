<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Modules\Core\Models\User;
use Modules\Desktop\Internal\Listeners\DispatchOsNotification;
use Modules\Desktop\Internal\Native\WindowFocusState;
use Modules\DriftAlerts\Public\Events\DriftAlertOpened;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Public\Dto\NotificationPreferencesDto;
use Modules\Notifications\Public\Events\NotificationDeliverable;
use Modules\Notifications\Public\Services\NotificationPreferenceQuery;

uses(RefreshDatabase::class);

/*
 * Req 9's acceptance criterion, verbatim: "A notification generated inside
 * the quiet window fires no OS notification AND is present in the inbox
 * afterward — nothing is silently dropped." Exercises the real delivery
 * path end to end: the REAL `DispatchOsNotification` listener is wired
 * onto `NotificationDeliverable` (normally bundle-gated in
 * `DesktopServiceProvider`, so it is registered manually here, mirroring
 * `PerTriggerToggleTest`) and asserted against real outbound
 * `/notification` HTTP POSTs — proving `SuppressionEvaluator`'s
 * quiet-hours decision (18-03's `SuppressionEvaluatorTest` proved the
 * decision logic in isolation) actually reaches the OS.
 *
 * Uses the drift-alert reactive trigger as the vehicle: quiet hours apply
 * uniformly to EVERY trigger type (unlike the D-08 per-trigger toggles,
 * which the always-deliverable reactive types bypass), so any trigger
 * proves the window. Each dispatch uses a distinct `driftAlertId` so the
 * D-05 deterministic id does not collapse three separate notifications
 * into one row.
 */

function qhdUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function qhdPairDevice(DatabaseManager $db, int $userId, string $deviceId = 'qhd-device'): void
{
    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => 'Self',
        'ed25519_public_key_hex' => str_repeat('a', 64),
        'x25519_public_key_hex' => str_repeat('b', 64),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 1,
        'paired_at' => '2026-07-01T10:00:00Z',
        'confirmed_at' => '2026-07-01T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-07-01T10:00:00Z',
        'updated_at' => '2026-07-01T10:00:00Z',
    ]);
}

function qhdSavePrefs(User $user, bool $quietHoursEnabled): void
{
    $prefs = new NotificationPreferencesDto(
        remindersEnabled: true,
        budgetNudgesEnabled: true,
        digestCadence: 'weekly',
        savingsPromptsEnabled: true,
        reminderLeadDays: 3,
        quietHoursEnabled: $quietHoursEnabled,
        quietHoursFrom: '22:00',
        quietHoursTo: '08:00',
        hideDetails: false,
    );

    app(NotificationPreferenceQuery::class)->saveForCurrentDevice($user, $prefs);
}

/** Wires the REAL DispatchOsNotification listener + unfocuses the window (D-13 gate open) so a delivered decision actually reaches an outbound HTTP POST. */
function qhdRegisterOsListener(): void
{
    Event::listen(NotificationDeliverable::class, [app(DispatchOsNotification::class), 'handleNotificationDeliverable']);
    app(WindowFocusState::class)->markBlurred();
}

function qhdOsFired(): bool
{
    return Http::recorded(fn ($request) => str_ends_with((string) $request->url(), '/notification'))->isNotEmpty();
}

/** Dispatches a drift-alert notification for $user with a distinct id; returns whether an OS notification fired. */
function qhdDispatchDrift(User $user, int $driftAlertId): bool
{
    Http::fake();

    /** @var Dispatcher $events */
    $events = app(Dispatcher::class);
    $events->dispatch(new DriftAlertOpened(
        userId: $user->id,
        driftAlertId: $driftAlertId,
        recurringSeriesId: 900,
        direction: 'up',
        deltaMinor: 250,
        annualizedImpactMinor: 3000,
        currency: 'EUR',
    ));

    return qhdOsFired();
}

function qhdInboxRowCount(int $userId): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('notifications')
        ->where('user_id', $userId)
        ->where('trigger_type', DeterministicKeyDeriver::TRIGGER_DRIFT_CHANGED)
        ->count();
}

function qhdIsUnread(int $userId): bool
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('notifications')
        ->where('user_id', $userId)
        ->where('trigger_type', DeterministicKeyDeriver::TRIGGER_DRIFT_CHANGED)
        ->value('read_at') === null;
}

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('fires no OS notification inside the quiet window AND persists exactly one inbox row (Req 9)', function (): void {
    $user = qhdUser('qhd-inside-window');
    qhdPairDevice(app(DatabaseManager::class), (int) $user->id);
    qhdSavePrefs($user, quietHoursEnabled: true);
    qhdRegisterOsListener();

    CarbonImmutable::setTestNow('2026-07-20 23:30:00');

    $fired = qhdDispatchDrift($user, 1001);

    expect($fired)->toBeFalse();
    expect(qhdInboxRowCount((int) $user->id))->toBe(1);
});

it('the deferred row is still present and unread the next morning (defer, never drop — Req 9)', function (): void {
    $user = qhdUser('qhd-survives-morning');
    qhdPairDevice(app(DatabaseManager::class), (int) $user->id);
    qhdSavePrefs($user, quietHoursEnabled: true);
    qhdRegisterOsListener();

    CarbonImmutable::setTestNow('2026-07-20 23:30:00');
    qhdDispatchDrift($user, 1002);

    expect(qhdInboxRowCount((int) $user->id))->toBe(1);

    // Advance past the quiet window into the next morning — the row must
    // remain, and remain unread (deferral, not deletion; nothing reads it
    // automatically).
    CarbonImmutable::setTestNow('2026-07-21 09:00:00');

    expect(qhdInboxRowCount((int) $user->id))->toBe(1);
    expect(qhdIsUnread((int) $user->id))->toBeTrue();
});

it('fires the OS notification at midday with quiet hours enabled (outside the window)', function (): void {
    $user = qhdUser('qhd-midday-enabled');
    qhdPairDevice(app(DatabaseManager::class), (int) $user->id);
    qhdSavePrefs($user, quietHoursEnabled: true);
    qhdRegisterOsListener();

    CarbonImmutable::setTestNow('2026-07-20 12:00:00');

    $fired = qhdDispatchDrift($user, 1003);

    expect($fired)->toBeTrue();
    expect(qhdInboxRowCount((int) $user->id))->toBe(1);
});

it('fires the OS notification at 23:30 when quiet hours are disabled (D-19: the window only applies when enabled)', function (): void {
    $user = qhdUser('qhd-disabled-at-2330');
    qhdPairDevice(app(DatabaseManager::class), (int) $user->id);
    qhdSavePrefs($user, quietHoursEnabled: false);
    qhdRegisterOsListener();

    CarbonImmutable::setTestNow('2026-07-20 23:30:00');

    $fired = qhdDispatchDrift($user, 1004);

    expect($fired)->toBeTrue();
    expect(qhdInboxRowCount((int) $user->id))->toBe(1);
});
