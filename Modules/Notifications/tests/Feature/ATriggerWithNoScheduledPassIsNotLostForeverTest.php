<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Public\Events\DriftAlertOpened;
use Modules\DriftAlerts\Tests\Support\DriftAlertFixture;
use Modules\EmailScan\Public\Services\IcsStatementReadyDispatch;
use Modules\Forecasting\Public\Events\ForecastShortfallDetected;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Notifications\Internal\Enums\DeferredNotificationPass;
use Modules\Notifications\Internal\Support\DeferredNotificationPasses;
use Modules\Notifications\Public\Enums\NotificationTrigger;
use Modules\Notifications\Public\Services\SuppressionEvaluator;
use Modules\Sync\Public\Exceptions\SensitiveColumnKeyUnavailableException;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(RefreshDatabase::class, EnablesEncryptionForUser::class);

// Three triggers are raised only from ShouldQueue jobs, and a queue worker
// builds its own empty session exactly the way the OS scheduler's process does.
// Until the writer recorded its own refusal, nothing durable said the content
// had been withheld, so the four passes nobody wrote a schedule hook for were
// not delayed by a locked device — they never arrived.

// What a queue worker resolves for Session::class: a real store that was never
// unlocked. Swapped into the container rather than handed to one collaborator,
// because `session.store` is a singleton and every consumer has to see it.
function tnspRunKeyless(callable $work): void
{
    $unlocked = app('session.store');
    app()->instance('session.store', new Store('tnsp-cold-process', new ArraySessionHandler(120)));

    try {
        app(SuppressionEvaluator::class)->suppressDelivery($work);
    } finally {
        app()->instance('session.store', $unlocked);
    }
}

// Counted by decrypting rather than by predicate: trigger_type is one of the
// four sealed columns, so `where('trigger_type', ...)` matches ciphertext with
// a plaintext needle and answers nothing for the very users this file is about.
/**
 * @return list<string>
 */
function tnspTriggers(int $userId, ?Session $session = null): array
{
    $session ??= app(Session::class);
    $codec = app(SensitiveColumnCodec::class);

    $triggers = app(DatabaseManager::class)->connection()
        ->table('notifications')
        ->where('user_id', $userId)
        ->pluck('trigger_type')
        ->map(static fn (mixed $stored): string => $codec->decryptValue(
            'notifications',
            'trigger_type',
            (string) $stored,
            $userId,
            $session,
        )['value'])
        ->all();

    sort($triggers);

    /** @var list<string> $triggers */
    return $triggers;
}

function tnspCount(int $userId, NotificationTrigger $trigger, ?Session $session = null): int
{
    return count(array_filter(
        tnspTriggers($userId, $session),
        static fn (string $value): bool => $value === $trigger->value,
    ));
}

function tnspReplay(int $userId, Session $session): void
{
    app(SuppressionEvaluator::class)->suppressDelivery(static function () use ($userId, $session): void {
        app(DeferredNotificationPasses::class)->runOutstanding($userId, $session);
    });
}

function tnspOutstanding(int $userId): array
{
    return app(DeferredNotificationPasses::class)->outstandingFor($userId);
}

function tnspUser(string $prefix): User
{
    return User::query()->create([
        'username' => $prefix.'-'.bin2hex(random_bytes(5)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function tnspAccount(User $user): int
{
    $suffix = bin2hex(random_bytes(4));

    return (int) app(DatabaseManager::class)->connection()->table('accounts')->insertGetId([
        'user_id' => $user->id,
        'name' => 'TNSP ASN',
        'slug' => 'tnsp-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00TNSP'.mb_strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);
}

// The window the keyless projection persisted. The projection itself needs no
// key and landed; only the announcement at the end of it was refused, which is
// why the repair reads this row back rather than sweeping the forecast again.
function tnspShortfallWindow(User $user, int $accountId): void
{
    app(DatabaseManager::class)->connection()->table('forecast_shortfall_windows')->insert([
        'user_id' => $user->id,
        'account_id' => $accountId,
        'scenario_id' => null,
        'starts_at' => '2026-08-03',
        'ends_at' => '2026-08-09',
        'lowest_balance_minor' => -4200,
        'currency' => 'EUR',
        'buffer_used_minor' => 0,
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);
}

function tnspShortfallEvent(User $user, int $accountId): ForecastShortfallDetected
{
    return new ForecastShortfallDetected(
        userId: (int) $user->id,
        accountId: $accountId,
        scenarioId: null,
        startsAt: CarbonImmutable::parse('2026-08-03'),
        endsAt: CarbonImmutable::parse('2026-08-09'),
        lowestBalanceMinor: -4200,
        currency: 'EUR',
        bufferUsedMinor: 0,
    );
}

function tnspImportRun(User $user): int
{
    return (int) app(DatabaseManager::class)->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'eml',
        'raw_file_path' => '/tmp/tnsp-'.bin2hex(random_bytes(4)).'.eml',
        'sha256' => hash('sha256', 'tnsp-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-07-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);
}

/**
 * @return list<CanonicalTransaction>
 */
function tnspCanonical(User $user, int $accountId, int $runId): array
{
    return [new CanonicalTransaction(
        userId: (int) $user->id,
        accountId: $accountId,
        type: 'expense',
        postedAt: CarbonImmutable::parse('2026-07-15'),
        bookedAt: CarbonImmutable::parse('2026-07-15 12:00:00'),
        valueDate: CarbonImmutable::parse('2026-07-15'),
        amountMinor: -1250,
        currency: 'EUR',
        settledAmountMinor: -1250,
        settledCurrency: 'EUR',
        counterpartyName: 'TNSP MARKT',
        counterpartyIban: null,
        counterpartyNormalized: 'tnsp-markt',
        normalizationVersion: 1,
        description: 'receipt fixture',
        categoryId: null,
        sourceFormat: 'eml',
        importRunId: $runId,
        sourceRowIndex: 1,
        sourceRef: null,
    )];
}

function tnspIcsMessage(User $user): void
{
    $db = app(DatabaseManager::class)->connection();
    $suffix = bin2hex(random_bytes(4));

    $inboxId = (int) $db->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'email' => 'tnsp-'.$suffix.'@example.com',
        'backfill_window_months' => 3,
        'backfill_progress' => null,
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);

    $db->table('inbox_messages')->insert([
        'user_id' => $user->id,
        'inbox_id' => $inboxId,
        'provider_message_id' => 'tnsp-'.$suffix,
        'internal_date' => '2026-07-15 08:00:00',
        'sender_email' => 'noreply@ics.nl',
        'sender_name' => 'ICS Cards',
        'subject' => 'Your statement is ready',
        'status' => 'fetched',
        'fetched_at' => '2026-07-15 08:05:00',
        'created_at' => '2026-07-15 08:05:00',
        'updated_at' => '2026-07-15 08:05:00',
    ]);
}

it('records a drift alert the keyless detector could not announce', function (): void {
    $user = tnspUser('tnsp-drift-mark');
    $this->enablesEncryptionForUser($user);
    $alert = DriftAlertFixture::alert($user);

    tnspRunKeyless(static fn () => app(Dispatcher::class)->dispatch(new DriftAlertOpened(
        userId: (int) $user->id,
        driftAlertId: (int) $alert->id,
        recurringSeriesId: (int) $alert->recurring_series_id,
        direction: 'expense',
        deltaMinor: (int) $alert->delta_minor,
        annualizedImpactMinor: (int) $alert->annualized_impact_minor,
        currency: 'EUR',
    )));

    expect(tnspCount((int) $user->id, NotificationTrigger::DriftChanged))->toBe(0);
    expect(tnspOutstanding((int) $user->id))->toContain(DeferredNotificationPass::WithheldTriggers);
});

// Re-announced from the row, not re-detected: DriftEvaluator emits
// DriftAlertOpened only when its insert wins the table's UNIQUE, so running the
// detector again over the alert it already opened says nothing at all.
it('delivers the drift notification on the next request that holds a key', function (): void {
    $user = tnspUser('tnsp-drift-replay');
    /** @var Session $session */
    $session = $this->enablesEncryptionForUser($user);
    $alert = DriftAlertFixture::alert($user);

    tnspRunKeyless(static fn () => app(Dispatcher::class)->dispatch(new DriftAlertOpened(
        userId: (int) $user->id,
        driftAlertId: (int) $alert->id,
        recurringSeriesId: (int) $alert->recurring_series_id,
        direction: 'expense',
        deltaMinor: (int) $alert->delta_minor,
        annualizedImpactMinor: (int) $alert->annualized_impact_minor,
        currency: 'EUR',
    )));

    tnspReplay((int) $user->id, $session);

    expect(tnspCount((int) $user->id, NotificationTrigger::DriftChanged))->toBe(1);
    expect(tnspOutstanding((int) $user->id))->toBe([]);
});

it('records a forecast shortfall the keyless projection could not announce', function (): void {
    $user = tnspUser('tnsp-forecast-mark');
    $this->enablesEncryptionForUser($user);
    $accountId = tnspAccount($user);
    tnspShortfallWindow($user, $accountId);

    tnspRunKeyless(static fn () => app(Dispatcher::class)->dispatch(tnspShortfallEvent($user, $accountId)));

    expect(tnspCount((int) $user->id, NotificationTrigger::ForecastShortfall))->toBe(0);
    expect(tnspOutstanding((int) $user->id))->toContain(DeferredNotificationPass::WithheldTriggers);
});

it('delivers the forecast shortfall on the next request that holds a key', function (): void {
    $user = tnspUser('tnsp-forecast-replay');
    /** @var Session $session */
    $session = $this->enablesEncryptionForUser($user);
    $accountId = tnspAccount($user);
    tnspShortfallWindow($user, $accountId);

    tnspRunKeyless(static fn () => app(Dispatcher::class)->dispatch(tnspShortfallEvent($user, $accountId)));

    tnspReplay((int) $user->id, $session);

    expect(tnspCount((int) $user->id, NotificationTrigger::ForecastShortfall))->toBe(1);
});

it('delivers the statement-ready nudge on the next request that holds a key', function (): void {
    $user = tnspUser('tnsp-ics-replay');
    /** @var Session $session */
    $session = $this->enablesEncryptionForUser($user);
    tnspIcsMessage($user);

    tnspRunKeyless(static fn () => app(IcsStatementReadyDispatch::class)->forUserNow((int) $user->id));

    expect(tnspCount((int) $user->id, NotificationTrigger::IcsStatementReady))->toBe(0);
    expect(tnspOutstanding((int) $user->id))->toContain(DeferredNotificationPass::WithheldTriggers);

    tnspReplay((int) $user->id, $session);

    expect(tnspCount((int) $user->id, NotificationTrigger::IcsStatementReady))->toBe(1);
});

// The mirror of every "expect 0" above. A count that is zero because the write
// never happened reads exactly like one that is zero because the emitter was
// broken, and only the keyed run tells the two apart.
it('writes all three straight away where the process does hold the key', function (): void {
    $user = tnspUser('tnsp-keyed');
    $this->enablesEncryptionForUser($user);
    $accountId = tnspAccount($user);
    tnspShortfallWindow($user, $accountId);
    tnspIcsMessage($user);
    $alert = DriftAlertFixture::alert($user);

    app(SuppressionEvaluator::class)->suppressDelivery(static function () use ($user, $accountId, $alert): void {
        app(Dispatcher::class)->dispatch(new DriftAlertOpened(
            userId: (int) $user->id,
            driftAlertId: (int) $alert->id,
            recurringSeriesId: (int) $alert->recurring_series_id,
            direction: 'expense',
            deltaMinor: (int) $alert->delta_minor,
            annualizedImpactMinor: (int) $alert->annualized_impact_minor,
            currency: 'EUR',
        ));
        app(Dispatcher::class)->dispatch(tnspShortfallEvent($user, $accountId));
        app(IcsStatementReadyDispatch::class)->forUserNow((int) $user->id);
    });

    expect(tnspCount((int) $user->id, NotificationTrigger::DriftChanged))->toBe(1);
    expect(tnspCount((int) $user->id, NotificationTrigger::ForecastShortfall))->toBe(1);
    expect(tnspCount((int) $user->id, NotificationTrigger::IcsStatementReady))->toBe(1);
    expect(tnspOutstanding((int) $user->id))->toBe([]);
});

// An install with plaintext columns has a scheduler that writes these fine, so
// a mark made for that user would replay work that already happened.
it('records nothing for a user who never enabled encryption', function (): void {
    $user = tnspUser('tnsp-plaintext');
    $accountId = tnspAccount($user);
    tnspShortfallWindow($user, $accountId);

    tnspRunKeyless(static fn () => app(Dispatcher::class)->dispatch(tnspShortfallEvent($user, $accountId)));

    expect(tnspCount((int) $user->id, NotificationTrigger::ForecastShortfall))->toBe(1);
    expect(tnspOutstanding((int) $user->id))->toBe([]);
});

// The row id is derived from the draft, so the replay writes what the keyless
// run would have written and nothing twice — which is what lets the mark cover
// every uncovered trigger instead of naming the one that set it.
it('writes the recovered notification once however often the replay runs', function (): void {
    $user = tnspUser('tnsp-twice');
    /** @var Session $session */
    $session = $this->enablesEncryptionForUser($user);
    $accountId = tnspAccount($user);
    tnspShortfallWindow($user, $accountId);

    tnspRunKeyless(static fn () => app(Dispatcher::class)->dispatch(tnspShortfallEvent($user, $accountId)));

    tnspReplay((int) $user->id, $session);
    app(DeferredNotificationPasses::class)->markWithheld((int) $user->id);
    tnspReplay((int) $user->id, $session);

    expect(tnspCount((int) $user->id, NotificationTrigger::ForecastShortfall))->toBe(1);
});

// The seam end to end, through a real request rather than a direct call: the
// middleware is on the `web` group of both roots and runs at terminate, so
// opening any page is what pays for the announcement the worker could not make.
it('re-derives the withheld notification from an ordinary authenticated request', function (): void {
    $user = tnspUser('tnsp-request');
    $this->enablesEncryptionForUser($user);
    $accountId = tnspAccount($user);
    tnspShortfallWindow($user, $accountId);

    tnspRunKeyless(static fn () => app(Dispatcher::class)->dispatch(tnspShortfallEvent($user, $accountId)));
    expect(tnspCount((int) $user->id, NotificationTrigger::ForecastShortfall))->toBe(0);

    $this->actingAs($user);
    app(SuppressionEvaluator::class)->suppressDelivery(function (): void {
        $this->get('/notifications')->assertOk();
    });

    expect(tnspCount((int) $user->id, NotificationTrigger::ForecastShortfall))->toBe(1);
});

// Why the four PersistCoalescedImport triggers answer no to
// reachableWithoutTheKey(): their announcement rides on a batch that was
// written first, and `transactions` carries sealed columns of its own. A
// keyless import is refused there, so what such a run loses is the import.
it('loses the import rather than its notification when a keyless process imports', function (): void {
    $user = tnspUser('tnsp-import');
    $this->enablesEncryptionForUser($user);
    $accountId = tnspAccount($user);
    $runId = tnspImportRun($user);

    $refusal = null;
    tnspRunKeyless(function () use ($user, $accountId, $runId, &$refusal): void {
        try {
            app(RecordsTransactions::class)(tnspCanonical($user, $accountId, $runId), $user);
        } catch (SensitiveColumnKeyUnavailableException $e) {
            $refusal = $e;
        }
    });

    expect($refusal)->not->toBeNull();
    expect(app(DatabaseManager::class)->connection()->table('transactions')->where('user_id', $user->id)->count())->toBe(0);
    expect(tnspTriggers((int) $user->id))->toBe([]);
    expect(tnspOutstanding((int) $user->id))->toBe([]);
});

// Measured on a copy of a real install: the midnight sweep refused a shortfall
// whose row the device already held, because the codec throws before the insert
// could have called it a duplicate. Marking there would make every later unlock
// re-derive content nothing was missing.
it('calls a withheld row that is already there a duplicate, not a deferral', function (): void {
    $user = tnspUser('tnsp-dup');
    /** @var Session $session */
    $session = $this->enablesEncryptionForUser($user);
    $accountId = tnspAccount($user);
    tnspShortfallWindow($user, $accountId);

    app(SuppressionEvaluator::class)->suppressDelivery(static fn () => app(Dispatcher::class)
        ->dispatch(tnspShortfallEvent($user, $accountId)));
    expect(tnspCount((int) $user->id, NotificationTrigger::ForecastShortfall, $session))->toBe(1);

    tnspRunKeyless(static fn () => app(Dispatcher::class)->dispatch(tnspShortfallEvent($user, $accountId)));

    expect(tnspOutstanding((int) $user->id))->toBe([]);
});
