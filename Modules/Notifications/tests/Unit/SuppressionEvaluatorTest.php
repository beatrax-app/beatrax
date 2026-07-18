<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Public\Services\SuppressionEvaluator;

uses(RefreshDatabase::class);

/*
 * SuppressionEvaluatorTest — the single delivery-suppression site (D-38
 * invariant 4). Exercises every toggle, both quiet-hours window shapes
 * (wrap-around + non-wrapping, inclusive lower / exclusive upper bound),
 * the four always-deliverable reactive types, and the D-43 seeding flag
 * (including flag restoration when the callback throws). hideDetails rides
 * on both delivered and suppressed outcomes.
 *
 * Lives in tests/Unit but is DB-backed (mirrors NotificationStateMachineTest)
 * because it reads through the real NotificationPreferenceQuery seam so an
 * unpaired/preference-less device transparently gets the D-16/D-19 defaults.
 */

function evalUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function seedSelfDevice(DatabaseManager $db, int $userId, string $deviceId = 'self-device'): void
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
function seedPrefs(DatabaseManager $db, int $userId, array $overrides = [], string $deviceId = 'self-device'): void
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

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    /** @var SuppressionEvaluator $evaluator */
    $evaluator = $this->app->make(SuppressionEvaluator::class);
    $this->evaluator = $evaluator;

    $this->at = CarbonImmutable::parse('2026-07-17 12:00:00');
});

it('suppresses only payment_reminder when reminders are disabled', function (): void {
    $user = evalUser('eval-reminders-off');
    seedSelfDevice($this->db, $user->id);
    seedPrefs($this->db, $user->id, ['reminders_enabled' => false]);

    $reminder = $this->evaluator->shouldDeliver($user->id, DeterministicKeyDeriver::TRIGGER_PAYMENT_REMINDER, $this->at);
    expect($reminder->deliver)->toBeFalse();
    expect($reminder->reason)->toBe('trigger_disabled');

    // Every OTHER toggle still delivers.
    expect($this->evaluator->shouldDeliver($user->id, DeterministicKeyDeriver::TRIGGER_BUDGET_NUDGE, $this->at)->deliver)->toBeTrue();
    expect($this->evaluator->shouldDeliver($user->id, DeterministicKeyDeriver::TRIGGER_SAVINGS_PROMPT, $this->at)->deliver)->toBeTrue();
    expect($this->evaluator->shouldDeliver($user->id, DeterministicKeyDeriver::TRIGGER_POSITION_DIGEST, $this->at)->deliver)->toBeTrue();
});

it('suppresses only budget_nudge when nudges are disabled', function (): void {
    $user = evalUser('eval-nudges-off');
    seedSelfDevice($this->db, $user->id);
    seedPrefs($this->db, $user->id, ['budget_nudges_enabled' => false]);

    expect($this->evaluator->shouldDeliver($user->id, DeterministicKeyDeriver::TRIGGER_BUDGET_NUDGE, $this->at)->deliver)->toBeFalse();
    expect($this->evaluator->shouldDeliver($user->id, DeterministicKeyDeriver::TRIGGER_PAYMENT_REMINDER, $this->at)->deliver)->toBeTrue();
    expect($this->evaluator->shouldDeliver($user->id, DeterministicKeyDeriver::TRIGGER_SAVINGS_PROMPT, $this->at)->deliver)->toBeTrue();
});

it('suppresses only savings_prompt when savings prompts are disabled', function (): void {
    $user = evalUser('eval-savings-off');
    seedSelfDevice($this->db, $user->id);
    seedPrefs($this->db, $user->id, ['savings_prompts_enabled' => false]);

    expect($this->evaluator->shouldDeliver($user->id, DeterministicKeyDeriver::TRIGGER_SAVINGS_PROMPT, $this->at)->deliver)->toBeFalse();
    expect($this->evaluator->shouldDeliver($user->id, DeterministicKeyDeriver::TRIGGER_PAYMENT_REMINDER, $this->at)->deliver)->toBeTrue();
    expect($this->evaluator->shouldDeliver($user->id, DeterministicKeyDeriver::TRIGGER_BUDGET_NUDGE, $this->at)->deliver)->toBeTrue();
});

it('suppresses position_digest when cadence is off', function (): void {
    $user = evalUser('eval-digest-off');
    seedSelfDevice($this->db, $user->id);
    seedPrefs($this->db, $user->id, ['digest_cadence' => 'off']);

    $digest = $this->evaluator->shouldDeliver($user->id, DeterministicKeyDeriver::TRIGGER_POSITION_DIGEST, $this->at);
    expect($digest->deliver)->toBeFalse();
    expect($digest->reason)->toBe('trigger_disabled');

    // Other toggles are unaffected by an 'off' digest cadence.
    expect($this->evaluator->shouldDeliver($user->id, DeterministicKeyDeriver::TRIGGER_PAYMENT_REMINDER, $this->at)->deliver)->toBeTrue();
});

it('delivers position_digest when cadence is daily or weekly', function (): void {
    $user = evalUser('eval-digest-on');
    seedSelfDevice($this->db, $user->id);
    seedPrefs($this->db, $user->id, ['digest_cadence' => 'daily']);

    $decision = $this->evaluator->shouldDeliver($user->id, DeterministicKeyDeriver::TRIGGER_POSITION_DIGEST, $this->at);
    expect($decision->deliver)->toBeTrue();
    expect($decision->reason)->toBe('ok');
});

it('always delivers the four reactive trigger types', function (): void {
    $user = evalUser('eval-reactive');
    seedSelfDevice($this->db, $user->id);
    // Every toggle off + quiet hours on — reactive types ignore all of it.
    seedPrefs($this->db, $user->id, [
        'reminders_enabled' => false,
        'budget_nudges_enabled' => false,
        'savings_prompts_enabled' => false,
        'digest_cadence' => 'off',
        'quiet_hours_enabled' => false,
    ]);

    foreach ([
        DeterministicKeyDeriver::TRIGGER_IMPORT_FINISHED,
        DeterministicKeyDeriver::TRIGGER_RECEIPTS_FOUND,
        DeterministicKeyDeriver::TRIGGER_DRIFT_CHANGED,
        DeterministicKeyDeriver::TRIGGER_FORECAST_SHORTFALL,
    ] as $trigger) {
        $decision = $this->evaluator->shouldDeliver($user->id, $trigger, $this->at);
        expect($decision->deliver)->toBeTrue();
        expect($decision->reason)->toBe('ok');
    }
});

it('applies a wrap-around quiet window 22:00-08:00', function (): void {
    $user = evalUser('eval-quiet-wrap');
    seedSelfDevice($this->db, $user->id);
    seedPrefs($this->db, $user->id, [
        'quiet_hours_enabled' => true,
        'quiet_hours_from' => '22:00',
        'quiet_hours_to' => '08:00',
    ]);

    $trigger = DeterministicKeyDeriver::TRIGGER_PAYMENT_REMINDER;
    $day = '2026-07-17';

    $suppressedAt = static fn (string $hm) => CarbonImmutable::parse($day.' '.$hm);

    // Suppressed inside the window.
    expect($this->evaluator->shouldDeliver($user->id, $trigger, $suppressedAt('23:30'))->reason)->toBe('quiet_hours');
    expect($this->evaluator->shouldDeliver($user->id, $trigger, $suppressedAt('02:00'))->reason)->toBe('quiet_hours');
    // Inclusive lower bound.
    expect($this->evaluator->shouldDeliver($user->id, $trigger, $suppressedAt('22:00'))->reason)->toBe('quiet_hours');

    // Exclusive upper bound delivers at 08:00, and midday delivers.
    expect($this->evaluator->shouldDeliver($user->id, $trigger, $suppressedAt('08:00'))->deliver)->toBeTrue();
    expect($this->evaluator->shouldDeliver($user->id, $trigger, $suppressedAt('12:00'))->deliver)->toBeTrue();
});

it('applies a non-wrapping quiet window 09:00-17:00', function (): void {
    $user = evalUser('eval-quiet-flat');
    seedSelfDevice($this->db, $user->id);
    seedPrefs($this->db, $user->id, [
        'quiet_hours_enabled' => true,
        'quiet_hours_from' => '09:00',
        'quiet_hours_to' => '17:00',
    ]);

    $trigger = DeterministicKeyDeriver::TRIGGER_PAYMENT_REMINDER;
    $day = '2026-07-17';

    expect($this->evaluator->shouldDeliver($user->id, $trigger, CarbonImmutable::parse($day.' 12:00'))->reason)->toBe('quiet_hours');
    expect($this->evaluator->shouldDeliver($user->id, $trigger, CarbonImmutable::parse($day.' 20:00'))->deliver)->toBeTrue();
});

it('suppresses everything with reason seeding inside suppressDelivery() and restores after a throw', function (): void {
    $user = evalUser('eval-seeding');
    seedSelfDevice($this->db, $user->id);
    seedPrefs($this->db, $user->id);

    $trigger = DeterministicKeyDeriver::TRIGGER_IMPORT_FINISHED; // normally always delivers

    $insideReason = $this->evaluator->suppressDelivery(function () use ($user, $trigger): string {
        return $this->evaluator->shouldDeliver($user->id, $trigger, $this->at)->reason;
    });
    expect($insideReason)->toBe('seeding');

    // Flag is restored in finally even when the callback throws.
    try {
        $this->evaluator->suppressDelivery(function (): void {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    $after = $this->evaluator->shouldDeliver($user->id, $trigger, $this->at);
    expect($after->deliver)->toBeTrue();
    expect($after->reason)->toBe('ok');
});

it('reflects hideDetails in both delivered and suppressed outcomes', function (): void {
    $user = evalUser('eval-hide-details');
    seedSelfDevice($this->db, $user->id);
    seedPrefs($this->db, $user->id, [
        'hide_details' => true,
        'reminders_enabled' => false,
    ]);

    // Delivered outcome (reactive type) carries hideDetails.
    $delivered = $this->evaluator->shouldDeliver($user->id, DeterministicKeyDeriver::TRIGGER_IMPORT_FINISHED, $this->at);
    expect($delivered->deliver)->toBeTrue();
    expect($delivered->hideDetails)->toBeTrue();

    // Suppressed outcome (disabled reminder) also carries hideDetails.
    $suppressed = $this->evaluator->shouldDeliver($user->id, DeterministicKeyDeriver::TRIGGER_PAYMENT_REMINDER, $this->at);
    expect($suppressed->deliver)->toBeFalse();
    expect($suppressed->hideDetails)->toBeTrue();
});

it('suppresses an unknown trigger type rather than defaulting to deliver', function (): void {
    $user = evalUser('eval-unknown');
    seedSelfDevice($this->db, $user->id);
    seedPrefs($this->db, $user->id);

    $decision = $this->evaluator->shouldDeliver($user->id, 'not_a_real_trigger', $this->at);
    expect($decision->deliver)->toBeFalse();
    expect($decision->reason)->toBe('trigger_disabled');
});
