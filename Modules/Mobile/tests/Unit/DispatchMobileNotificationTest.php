<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Mobile\Internal\Listeners\DispatchMobileNotification;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Public\Events\NotificationDeliverable;
use Modules\Notifications\Public\Services\SuppressionEvaluator;

uses(RefreshDatabase::class);

// nativephp/mobile-local-notifications ships no fake for its LocalNotifications
// facade, so the recording subclass below overrides the protected fire() seam
// rather than asserting an outbound call. The real class is exercised in the
// plugin-absent test, which is the case every CI machine runs.

/**
 * @param  array<int, array{notificationId: string, title: string, body: string, deepLinkRoute: string|null}>  $fired
 */
final class RecordingDispatchMobileNotification extends DispatchMobileNotification
{
    /** @var array<int, array{notificationId: string, title: string, body: string, deepLinkRoute: string|null}> */
    public array $fired = [];

    protected function fire(string $notificationId, string $title, string $body, ?string $deepLinkRoute): void
    {
        $this->fired[] = [
            'notificationId' => $notificationId,
            'title' => $title,
            'body' => $body,
            'deepLinkRoute' => $deepLinkRoute,
        ];
    }
}

function donMobileUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function donMobileSeedSelfDevice(DatabaseManager $db, int $userId, string $deviceId = 'don-mobile-self-device'): void
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

/**
 * @param  array<string, mixed>  $overrides
 */
function donMobileSeedPrefs(DatabaseManager $db, int $userId, array $overrides = [], string $deviceId = 'don-mobile-self-device'): void
{
    $db->connection()->table('notification_preferences')->insert(array_merge([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'reminders_enabled' => true,
        'budget_nudges_enabled' => true,
        'digest_cadence' => 'weekly',
        'savings_prompts_enabled' => true,
        'reminder_lead_days' => 3,
        'quiet_hours_enabled' => false,
        'quiet_hours_from' => '22:00',
        'quiet_hours_to' => '08:00',
        'hide_details' => false,
        'created_at' => '2026-07-01T10:00:00Z',
        'updated_at' => '2026-07-01T10:00:00Z',
    ], $overrides));
}

function donMobileForecastDeliverable(int $userId): NotificationDeliverable
{
    return new NotificationDeliverable(
        notificationId: hash('sha256', 'don-mobile-forecast-'.$userId),
        userId: $userId,
        triggerType: DeterministicKeyDeriver::TRIGGER_FORECAST_SHORTFALL,
        title: 'Cash-flow shortfall ahead',
        body: 'Your projected balance dips below zero within the next 30 days.',
        deepLinkRoute: '/forecast',
    );
}

it('fires nothing when SuppressionEvaluator suppresses delivery (D-38 invariant 4)', function (): void {
    $user = donMobileUser('don-mobile-suppressed');

    /** @var RecordingDispatchMobileNotification $listener */
    $listener = app(RecordingDispatchMobileNotification::class);
    /** @var SuppressionEvaluator $suppression */
    $suppression = app(SuppressionEvaluator::class);

    $suppression->suppressDelivery(function () use ($listener, $user): void {
        $listener->handleNotificationDeliverable(donMobileForecastDeliverable($user->id));
    });

    expect($listener->fired)->toBeEmpty();
});

it('reaches the guarded fire path with a deliverable decision (no focus-gate, unlike Desktop)', function (): void {
    $user = donMobileUser('don-mobile-deliverable');

    /** @var RecordingDispatchMobileNotification $listener */
    $listener = app(RecordingDispatchMobileNotification::class);

    $deliverable = donMobileForecastDeliverable($user->id);
    $listener->handleNotificationDeliverable($deliverable);

    expect($listener->fired)->toHaveCount(1)
        ->and($listener->fired[0]['notificationId'])->toBe($deliverable->notificationId)
        ->and($listener->fired[0]['title'])->toBe($deliverable->title)
        ->and($listener->fired[0]['body'])->toBe($deliverable->body)
        ->and($listener->fired[0]['deepLinkRoute'])->toBe($deliverable->deepLinkRoute);
});

it('substitutes the detail-free body when the device hide-details preference is on (D-24)', function (): void {
    $user = donMobileUser('don-mobile-hide-details');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    donMobileSeedSelfDevice($db, $user->id);
    donMobileSeedPrefs($db, $user->id, ['hide_details' => true]);

    /** @var RecordingDispatchMobileNotification $listener */
    $listener = app(RecordingDispatchMobileNotification::class);

    $deliverable = donMobileForecastDeliverable($user->id);
    $listener->handleNotificationDeliverable($deliverable);

    expect($listener->fired)->toHaveCount(1);
    $firedBody = $listener->fired[0]['body'];
    expect($firedBody)->not->toBe('')
        ->and($firedBody)->not->toBe($deliverable->body);
});

it('never throws when the plugin class is absent — the class_exists guard every CI machine actually runs', function (): void {
    $user = donMobileUser('don-mobile-plugin-absent');

    /** @var DispatchMobileNotification $listener */
    $listener = app(DispatchMobileNotification::class);

    $listener->handleNotificationDeliverable(donMobileForecastDeliverable($user->id));
})->throwsNoExceptions();
