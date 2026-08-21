<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Notifications\Internal\StateMachines\NotificationStateMachine;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Public\Services\SuppressionEvaluator;
use Modules\Recurring\Internal\Jobs\EmitPaymentRemindersJob;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Models\RecurringSeriesOccurrence;
use Modules\Recurring\Public\Events\PaymentSettled;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

// A charge that settles before the job runs is skipped outright; one that
// settles after resolves the persisted row by re-deriving its id. Dispatch runs
// inside suppressDelivery() so no case attempts a real OS notification.

function rsiUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function rsiSeries(User $user, string $clusterKey, CarbonImmutable $nextExpectedAt): RecurringSeries
{
    return RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'RSI MERCHANT',
        'display_name_override' => null,
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1299,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -1299,
        'variance_tolerance_percent' => 25,
        'next_expected_at' => $nextExpectedAt,
        'next_expected_confidence_low' => false,
        'cluster_key' => $clusterKey,
        'cluster_counterparty_key' => 'RSI MERCHANT',
    ]);
}

// The settlement check looks for a real occurrence row, so a bare transaction
// is not enough.
function rsiSeedOccurrence(User $user, RecurringSeries $series, CarbonImmutable $observedAt): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $suffix = bin2hex(random_bytes(4));

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'RSI ASN',
        'slug' => 'rsi-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00RSI'.strtoupper($suffix),
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/rsi-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'rsi-run-'.$suffix),
        'uploaded_at' => $observedAt,
        'status' => 'previewed',
    ]);

    $transactionId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => $run->id,
        'fingerprint' => hash('sha256', 'rsi-'.bin2hex(random_bytes(8))),
        'posted_at' => $observedAt->toDateString(),
        'booked_at' => $observedAt->toDateTimeString(),
        'value_date' => $observedAt->toDateString(),
        'amount_minor' => -1299,
        'currency' => 'EUR',
        'settled_amount_minor' => -1299,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'rsi-merchant',
        'counterparty_name' => 'RSI MERCHANT',
        'normalization_version' => 1,
        'description' => 'rsi fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => $observedAt->toDateTimeString(),
        'updated_at' => $observedAt->toDateTimeString(),
    ]);

    RecurringSeriesOccurrence::query()->create([
        'user_id' => $user->id,
        'recurring_series_id' => $series->id,
        'transaction_id' => $transactionId,
        'observed_at' => $observedAt,
        'observed_amount_minor' => -1299,
        'observed_currency' => 'EUR',
    ]);
}

function rsiRunJob(User $user, int $leadDays = 3): void
{
    /** @var SuppressionEvaluator $suppression */
    $suppression = app(SuppressionEvaluator::class);

    $suppression->suppressDelivery(function () use ($user, $leadDays): void {
        $job = new EmitPaymentRemindersJob($user->id, $leadDays);
        $job->handle(
            app(RecurringSeriesQuery::class),
            app(Dispatcher::class),
            app(Clock::class),
        );
    });
}

function rsiDispatchSettled(User $user, RecurringSeries $series, CarbonImmutable $dueDate): void
{
    /** @var SuppressionEvaluator $suppression */
    $suppression = app(SuppressionEvaluator::class);
    /** @var Dispatcher $events */
    $events = app(Dispatcher::class);

    $suppression->suppressDelivery(function () use ($events, $user, $series, $dueDate): void {
        $events->dispatch(new PaymentSettled(
            userId: (int) $user->id,
            seriesId: $series->id,
            dueDate: $dueDate,
        ));
    });
}

function rsiNotificationRow(int $userId, int $seriesId, string $dueDate): ?object
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    /** @var DeterministicKeyDeriver $keys */
    $keys = app(DeterministicKeyDeriver::class);

    $id = $keys->derive($userId, DeterministicKeyDeriver::TRIGGER_PAYMENT_REMINDER, (string) $seriesId, $dueDate);

    return $db->connection()->table('notifications')->where('id', $id)->first();
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-20 09:15:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('does not fire a reminder for a charge matched before the job runs', function (): void {
    $user = rsiUser('rsi-pre-matched');
    $due = CarbonImmutable::parse('2026-07-22');
    $series = rsiSeries($user, 'rsi-pre-matched', $due);

    // The charge already landed for this due date, before the job ever ran.
    rsiSeedOccurrence($user, $series, $due);

    rsiRunJob($user);

    $row = rsiNotificationRow((int) $user->id, (int) $series->id, $due->toDateString());
    expect($row)->toBeNull();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    expect($db->connection()->table('notifications')->where('user_id', $user->id)->count())->toBe(0);
});

it('resolves the existing row when the charge settles after delivery', function (): void {
    $user = rsiUser('rsi-post-delivery');
    $due = CarbonImmutable::parse('2026-07-22');
    $series = rsiSeries($user, 'rsi-post-delivery', $due);

    rsiRunJob($user);

    $delivered = rsiNotificationRow((int) $user->id, (int) $series->id, $due->toDateString());
    expect($delivered)->not->toBeNull();
    expect($delivered?->state)->toBe('open');
    $originalTitle = $delivered?->title;
    $originalBody = $delivered?->body;

    rsiDispatchSettled($user, $series, $due);

    $resolved = rsiNotificationRow((int) $user->id, (int) $series->id, $due->toDateString());
    expect($resolved)->not->toBeNull();
    expect($resolved?->state)->toBe('resolved');
    // Resolving must not rewrite the copy; the row stays legible as history.
    expect($resolved?->title)->toBe($originalTitle);
    expect($resolved?->body)->toBe($originalBody);
});

it('is a no-op with no exception when PaymentSettled has no matching reminder row', function (): void {
    $user = rsiUser('rsi-no-matching-row');
    $due = CarbonImmutable::parse('2026-07-22');
    $series = rsiSeries($user, 'rsi-no-matching-row', $due);

    // No reminder was ever fired for this (series, due date) pair.
    rsiDispatchSettled($user, $series, $due);

    $row = rsiNotificationRow((int) $user->id, (int) $series->id, $due->toDateString());
    expect($row)->toBeNull();
});

it('does not let a resolved row be resolved twice', function (): void {
    $user = rsiUser('rsi-double-resolve');
    $due = CarbonImmutable::parse('2026-07-22');
    $series = rsiSeries($user, 'rsi-double-resolve', $due);

    rsiRunJob($user);
    rsiDispatchSettled($user, $series, $due);

    $firstResolve = rsiNotificationRow((int) $user->id, (int) $series->id, $due->toDateString());
    expect($firstResolve?->state)->toBe('resolved');

    // The listener's pre-check keeps the second dispatch away from the state
    // machine, which would otherwise reject resolved -> resolved by throwing.
    rsiDispatchSettled($user, $series, $due);

    $secondResolve = rsiNotificationRow((int) $user->id, (int) $series->id, $due->toDateString());
    expect($secondResolve?->state)->toBe('resolved');
});

it('rejects a direct resolved -> resolved transition via the state machine', function (): void {
    $user = rsiUser('rsi-state-machine-guard');
    $due = CarbonImmutable::parse('2026-07-22');
    $series = rsiSeries($user, 'rsi-state-machine-guard', $due);

    rsiRunJob($user);

    /** @var DeterministicKeyDeriver $keys */
    $keys = app(DeterministicKeyDeriver::class);
    $id = $keys->derive((int) $user->id, DeterministicKeyDeriver::TRIGGER_PAYMENT_REMINDER, (string) $series->id, $due->toDateString());

    /** @var NotificationStateMachine $machine */
    $machine = app(NotificationStateMachine::class);
    $machine->resolve($id, (int) $user->id);

    expect(fn () => $machine->resolve($id, (int) $user->id))->toThrow(RuntimeException::class);
});
