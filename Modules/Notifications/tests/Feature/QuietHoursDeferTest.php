<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\DigestCadence;
use Modules\Desktop\Internal\Listeners\DispatchOsNotification;
use Modules\Desktop\Internal\Native\WindowFocusState;
use Modules\DriftAlerts\Public\Events\DriftAlertOpened;
use Modules\Notifications\Public\Dto\NotificationPreferencesDto;
use Modules\Notifications\Public\Enums\NotificationTrigger;
use Modules\Notifications\Public\Events\NotificationDeliverable;
use Modules\Notifications\Public\Services\NotificationPreferenceQuery;

uses(RefreshDatabase::class);

// The real DispatchOsNotification listener is bundle-gated in
// DesktopServiceProvider, so it is wired by hand here. The vehicle is the
// drift-alert trigger because quiet hours apply to every type alike; each
// dispatch needs a distinct driftAlertId or the ids collapse into one row.

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
        digestCadence: DigestCadence::Weekly,
        savingsPromptsEnabled: true,
        reminderLeadDays: 3,
        quietHoursEnabled: $quietHoursEnabled,
        quietHoursFrom: '22:00',
        quietHoursTo: '08:00',
        hideDetails: false,
    );

    app(NotificationPreferenceQuery::class)->saveForCurrentDevice($user, $prefs);
}

// The window has to be blurred too, or the focus gate swallows the POST before
// the quiet-hours decision is visible.
function qhdRegisterOsListener(): void
{
    Event::listen(NotificationDeliverable::class, [app(DispatchOsNotification::class), 'handleNotificationDeliverable']);
    app(WindowFocusState::class)->markBlurred();
}

function qhdOsFired(): bool
{
    return Http::recorded(fn ($request) => str_ends_with((string) $request->url(), '/notification'))->isNotEmpty();
}

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
        ->where('trigger_type', NotificationTrigger::DriftChanged)
        ->count();
}

function qhdIsUnread(int $userId): bool
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('notifications')
        ->where('user_id', $userId)
        ->where('trigger_type', NotificationTrigger::DriftChanged)
        ->value('read_at') === null;
}

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('fires no OS notification inside the quiet window AND persists exactly one inbox row', function (): void {
    $user = qhdUser('qhd-inside-window');
    qhdPairDevice(app(DatabaseManager::class), (int) $user->id);
    qhdSavePrefs($user, quietHoursEnabled: true);
    qhdRegisterOsListener();

    CarbonImmutable::setTestNow('2026-07-20 23:30:00');

    $fired = qhdDispatchDrift($user, 1001);

    expect($fired)->toBeFalse();
    expect(qhdInboxRowCount((int) $user->id))->toBe(1);
});

it('the deferred row is still present and unread the next morning (defer, never drop)', function (): void {
    $user = qhdUser('qhd-survives-morning');
    qhdPairDevice(app(DatabaseManager::class), (int) $user->id);
    qhdSavePrefs($user, quietHoursEnabled: true);
    qhdRegisterOsListener();

    CarbonImmutable::setTestNow('2026-07-20 23:30:00');
    qhdDispatchDrift($user, 1002);

    expect(qhdInboxRowCount((int) $user->id))->toBe(1);

    // Past the quiet window: the row must still be there and still unread.
    // Quiet hours defer delivery, they do not drop or auto-read anything.
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

it('fires the OS notification at 23:30 when quiet hours are disabled (the window only applies when enabled)', function (): void {
    $user = qhdUser('qhd-disabled-at-2330');
    qhdPairDevice(app(DatabaseManager::class), (int) $user->id);
    qhdSavePrefs($user, quietHoursEnabled: false);
    qhdRegisterOsListener();

    CarbonImmutable::setTestNow('2026-07-20 23:30:00');

    $fired = qhdDispatchDrift($user, 1004);

    expect($fired)->toBeTrue();
    expect(qhdInboxRowCount((int) $user->id))->toBe(1);
});
