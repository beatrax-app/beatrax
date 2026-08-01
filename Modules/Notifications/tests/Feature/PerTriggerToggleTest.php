<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Modules\Budgets\Internal\Jobs\EmitBudgetNudgesJob;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Desktop\Internal\Listeners\DispatchOsNotification;
use Modules\Desktop\Internal\Native\WindowFocusState;
use Modules\DriftAlerts\Internal\Jobs\EmitSavingsPromptsJob;
use Modules\DriftAlerts\Public\Services\SavingsInsightsQuery;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Public\Dto\NotificationPreferencesDto;
use Modules\Notifications\Public\Enums\DigestCadence;
use Modules\Notifications\Public\Events\NotificationDeliverable;
use Modules\Notifications\Public\Services\NotificationPreferenceQuery;
use Modules\Position\Internal\Jobs\EmitPositionDigestJob;
use Modules\Position\Public\Services\PositionQuery;
use Modules\Recurring\Internal\Jobs\EmitPaymentRemindersJob;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

uses(RefreshDatabase::class);

/*
 * Req 8's acceptance criterion, verbatim: "Disabling each trigger in turn
 * and running its job produces zero notifications for that trigger while
 * others still fire." 18-03's `SuppressionEvaluatorTest` already proved the
 * decision LOGIC in isolation; THIS test proves the decision is actually
 * honoured by the real delivery path — the REAL `DispatchOsNotification`
 * listener is wired onto `NotificationDeliverable` (normally bundle-gated
 * in `DesktopServiceProvider`, so it is registered manually here) and
 * asserted against real outbound `/notification` HTTP POSTs, exactly the
 * harness `DispatchOsNotificationTest` already established.
 *
 * SPEC-LOCK (18-CONTEXT.md `<spec_lock>`): Req 8's "a disabled trigger
 * emits nothing" means emits no OS notification (D-08) — the row is NEVER
 * dropped, exactly as Req 9's own precedent establishes. A verifier must
 * not read the always-persisted row as drift from Req 8.
 *
 * Position digest is the one proactive trigger whose "disabled" state
 * (`digestCadence = 'off'`) is ALSO a Req 5 JOB-LEVEL gate — an off-cadence
 * digest job never emits a row at all (proven by
 * `PositionDigestCadenceTest`), a materially different mechanism from the
 * plain on/off toggles the other three triggers use, which
 * `SuppressionEvaluator` alone gates. To isolate the D-38/D-08
 * delivery-suppression behaviour under test here — and keep "the disabled
 * trigger's row is still in the inbox" true for ALL four triggers — the
 * position-digest iteration always calls the job with a real cadence
 * ('daily', so a row is always written) while the PREFERENCE
 * `SuppressionEvaluator` reads is set to 'off' independently. Production
 * wiring keeps both values in sync (the scheduler reads the same
 * preference it passes to the job); this test deliberately decouples them
 * so it exercises only the suppression path Req 8 names.
 */

function pttUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function pttPairDevice(DatabaseManager $db, int $userId, string $deviceId = 'ptt-device'): void
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
 * @param  array{remindersEnabled: bool, budgetNudgesEnabled: bool, digestCadence: DigestCadence, savingsPromptsEnabled: bool}  $overrides
 */
function pttSavePrefs(User $user, array $overrides): void
{
    $prefs = new NotificationPreferencesDto(
        remindersEnabled: $overrides['remindersEnabled'],
        budgetNudgesEnabled: $overrides['budgetNudgesEnabled'],
        digestCadence: $overrides['digestCadence'],
        savingsPromptsEnabled: $overrides['savingsPromptsEnabled'],
        reminderLeadDays: 3,
        quietHoursEnabled: false,
        quietHoursFrom: '22:00',
        quietHoursTo: '08:00',
        hideDetails: false,
    );

    app(NotificationPreferenceQuery::class)->saveForCurrentDevice($user, $prefs);
}

/** Wires the REAL DispatchOsNotification listener + unfocuses the window (D-13 gate open) so a delivered decision actually reaches an outbound HTTP POST. */
function pttRegisterOsListener(): void
{
    Event::listen(NotificationDeliverable::class, [app(DispatchOsNotification::class), 'handleNotificationDeliverable']);
    app(WindowFocusState::class)->markBlurred();
}

function pttOsFired(): bool
{
    return Http::recorded(fn ($request) => str_ends_with((string) $request->url(), '/notification'))->isNotEmpty();
}

/** Seeds a recurring series due inside the lead window + runs EmitPaymentRemindersJob; returns whether an OS notification fired. */
function pttRunReminder(User $user): bool
{
    Http::fake();

    RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'PTT MERCHANT',
        'display_name_override' => null,
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1299,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -1299,
        'variance_tolerance_percent' => 25,
        'next_expected_at' => CarbonImmutable::now()->addDays(2),
        'next_expected_confidence_low' => false,
        'cluster_key' => 'ptt-reminder-'.$user->id,
        'cluster_counterparty_key' => 'PTT MERCHANT',
    ]);

    $job = new EmitPaymentRemindersJob($user->id, 3);
    $job->handle(app(RecurringSeriesQuery::class), app(Dispatcher::class), app(Clock::class));

    return pttOsFired();
}

/** Seeds an envelope spent past its threshold + runs EmitBudgetNudgesJob; returns whether an OS notification fired. */
function pttRunBudgetNudge(User $user): bool
{
    Http::fake();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('users')->where('id', $user->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->startOfMonth(),
    ]);

    $category = Category::create([
        'user_id' => null,
        'name' => 'PttCategory',
        'slug' => 'ptt-category-'.bin2hex(random_bytes(4)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    $account = Account::create([
        'user_id' => $user->id,
        'name' => 'PTT ASN',
        'slug' => 'ptt-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00PTT'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/ptt-'.bin2hex(random_bytes(4)).'.xml',
        'sha256' => hash('sha256', 'ptt-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    app(EnvelopeWriter::class)->setAssigned($user, $category->id, CarbonImmutable::now()->startOfMonth(), 10000);

    Transaction::create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => CarbonImmutable::now()->toDateString(),
        'booked_at' => CarbonImmutable::now()->toDateString().' 12:00:00',
        'value_date' => CarbonImmutable::now()->toDateString(),
        'amount_minor' => -9500,
        'currency' => 'EUR',
        'settled_amount_minor' => -9500,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'PttMerchant',
        'counterparty_normalized' => 'pttmerchant',
        'normalization_version' => 1,
        'category_id' => $category->id,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('ptt'.$user->id, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    $job = new EmitBudgetNudgesJob($user->id);
    $job->handle(app(CarryoverQuery::class), app(Clock::class), app(Dispatcher::class), app(AuthFactory::class));

    return pttOsFired();
}

/** Runs EmitPositionDigestJob with a fixed 'daily' cadence (see file docblock); returns whether an OS notification fired. */
function pttRunDigest(User $user): bool
{
    Http::fake();

    $job = new EmitPositionDigestJob($user->id, 'daily');
    $job->handle(app(Clock::class), app(PositionQuery::class), app(PeriodQuery::class), app(Dispatcher::class), app(AuthFactory::class));

    return pttOsFired();
}

/** Seeds an approved-subscription savings-insight chain + runs EmitSavingsPromptsJob; returns whether an OS notification fired. */
function pttRunSavingsPrompt(User $user): bool
{
    Http::fake();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // Must match a real bundled support-resource corpus entry
    // (`SupportResourceProvider` word-prefix matching) for the insight to
    // resolve a `cheaper_url` — "Spotify" is the same fixture merchant
    // `SavingsPromptTriggerTest`'s `sptChain()` uses.
    $merchant = 'Spotify';
    $monthlyMinor = 999;

    $cpId = $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $user->id, 'type' => 'merchant', 'slug' => strtolower($merchant).'-ptt-'.bin2hex(random_bytes(3)),
        'display_name' => $merchant, 'merchant_name' => $merchant,
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $seriesId = $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $user->id, 'direction' => 'expense', 'detected_name' => $merchant,
        'state' => 'approved', 'cadence' => 'monthly', 'latest_amount_minor' => -$monthlyMinor, 'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -$monthlyMinor, 'variance_tolerance_percent' => 25,
        'cluster_key' => $merchant.'|monthly|EUR|'.bin2hex(random_bytes(3)),
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $user->id, 'name' => 'ASN', 'slug' => 'ptt-spt-'.bin2hex(random_bytes(4)),
        'kind' => 'bank', 'iban' => 'NL00SPT'.str_pad((string) $cpId, 9, '0', STR_PAD_LEFT), 'default_currency' => 'EUR',
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/ptt-spt.csv',
        'sha256' => str_pad('pttspt'.$cpId, 64, 'a', STR_PAD_LEFT), 'uploaded_at' => '2026-05-01 00:00:00', 'status' => 'previewed',
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $txId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $user->id, 'account_id' => $accountId, 'import_run_id' => $runId, 'counterparty_id' => $cpId,
        'fingerprint' => str_pad('pttspt'.$cpId, 64, 'c', STR_PAD_LEFT), 'posted_at' => '2026-05-01',
        'booked_at' => '2026-05-01 00:00:00', 'value_date' => '2026-05-01',
        'amount_minor' => -$monthlyMinor, 'currency' => 'EUR', 'settled_amount_minor' => -$monthlyMinor, 'settled_currency' => 'EUR',
        'counterparty_normalized' => strtolower($merchant), 'counterparty_name' => strtoupper($merchant), 'normalization_version' => 1,
        'type' => 'expense', 'source_format' => 'asn-csv', 'source_row_index' => $cpId,
        'fingerprint_version' => 3, 'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $db->connection()->table('recurring_series_occurrences')->insert([
        'user_id' => $user->id, 'recurring_series_id' => $seriesId, 'transaction_id' => $txId,
        'observed_at' => '2026-05-01', 'observed_amount_minor' => -$monthlyMinor, 'observed_currency' => 'EUR',
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);

    $job = new EmitSavingsPromptsJob($user->id);
    $job->handle(app(SavingsInsightsQuery::class), app(Dispatcher::class));

    return pttOsFired();
}

function pttNotificationRowExists(int $userId, string $triggerType): bool
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('notifications')
        ->where('user_id', $userId)
        ->where('trigger_type', $triggerType)
        ->exists();
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-20 09:00:00');
    pttRegisterOsListener();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('disabling payment_reminder fires no OS notification for it while the other three fire, and its row stays in the inbox (Req 8)', function (): void {
    $user = pttUser('ptt-disable-reminder');
    pttPairDevice(app(DatabaseManager::class), (int) $user->id);
    pttSavePrefs($user, [
        'remindersEnabled' => false,
        'budgetNudgesEnabled' => true,
        'digestCadence' => DigestCadence::Daily,
        'savingsPromptsEnabled' => true,
    ]);

    expect(pttRunReminder($user))->toBeFalse();
    expect(pttRunBudgetNudge($user))->toBeTrue();
    expect(pttRunDigest($user))->toBeTrue();
    expect(pttRunSavingsPrompt($user))->toBeTrue();

    expect(pttNotificationRowExists((int) $user->id, DeterministicKeyDeriver::TRIGGER_PAYMENT_REMINDER))->toBeTrue();
});

it('disabling budget_nudge fires no OS notification for it while the other three fire, and its row stays in the inbox (Req 8)', function (): void {
    $user = pttUser('ptt-disable-budget');
    pttPairDevice(app(DatabaseManager::class), (int) $user->id);
    pttSavePrefs($user, [
        'remindersEnabled' => true,
        'budgetNudgesEnabled' => false,
        'digestCadence' => DigestCadence::Daily,
        'savingsPromptsEnabled' => true,
    ]);

    expect(pttRunReminder($user))->toBeTrue();
    expect(pttRunBudgetNudge($user))->toBeFalse();
    expect(pttRunDigest($user))->toBeTrue();
    expect(pttRunSavingsPrompt($user))->toBeTrue();

    expect(pttNotificationRowExists((int) $user->id, DeterministicKeyDeriver::TRIGGER_BUDGET_NUDGE))->toBeTrue();
});

it('disabling position_digest (cadence off) fires no OS notification for it while the other three fire, and its row stays in the inbox (Req 8)', function (): void {
    $user = pttUser('ptt-disable-digest');
    pttPairDevice(app(DatabaseManager::class), (int) $user->id);
    pttSavePrefs($user, [
        'remindersEnabled' => true,
        'budgetNudgesEnabled' => true,
        'digestCadence' => DigestCadence::Off,
        'savingsPromptsEnabled' => true,
    ]);

    expect(pttRunReminder($user))->toBeTrue();
    expect(pttRunBudgetNudge($user))->toBeTrue();
    expect(pttRunDigest($user))->toBeFalse();
    expect(pttRunSavingsPrompt($user))->toBeTrue();

    expect(pttNotificationRowExists((int) $user->id, DeterministicKeyDeriver::TRIGGER_POSITION_DIGEST))->toBeTrue();
});

it('disabling savings_prompt fires no OS notification for it while the other three fire, and its row stays in the inbox (Req 8)', function (): void {
    $user = pttUser('ptt-disable-savings');
    pttPairDevice(app(DatabaseManager::class), (int) $user->id);
    pttSavePrefs($user, [
        'remindersEnabled' => true,
        'budgetNudgesEnabled' => true,
        'digestCadence' => DigestCadence::Daily,
        'savingsPromptsEnabled' => false,
    ]);

    expect(pttRunReminder($user))->toBeTrue();
    expect(pttRunBudgetNudge($user))->toBeTrue();
    expect(pttRunDigest($user))->toBeTrue();
    expect(pttRunSavingsPrompt($user))->toBeFalse();

    expect(pttNotificationRowExists((int) $user->id, DeterministicKeyDeriver::TRIGGER_SAVINGS_PROMPT))->toBeTrue();
});
