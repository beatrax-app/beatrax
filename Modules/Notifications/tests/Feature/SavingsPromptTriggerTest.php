<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Internal\Jobs\EmitSavingsPromptsJob;
use Modules\DriftAlerts\Public\Services\SavingsInsightsQuery;
use Modules\Notifications\Public\Enums\NotificationTrigger;
use Modules\Notifications\Public\Services\SuppressionEvaluator;

// Dispatch runs inside suppressDelivery() so no case here attempts a real OS
// notification.

function sptUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// "Spotify" is not arbitrary: the insight only resolves a cheaper_url for a
// counterparty the bundled support-resource corpus knows.
function sptChain(DatabaseManager $db, int $userId, string $merchant, int $monthlyMinor): int
{
    $cpId = $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId, 'type' => 'merchant', 'slug' => strtolower($merchant).'-spt-'.bin2hex(random_bytes(3)),
        'display_name' => $merchant, 'merchant_name' => $merchant,
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $seriesId = $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId, 'direction' => 'expense', 'detected_name' => $merchant,
        'state' => 'approved', 'cadence' => 'monthly', 'latest_amount_minor' => -$monthlyMinor, 'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -$monthlyMinor, 'variance_tolerance_percent' => 25,
        'cluster_key' => $merchant.'|monthly|EUR|'.bin2hex(random_bytes(3)),
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN', 'slug' => 'spt-'.bin2hex(random_bytes(4)),
        'kind' => 'bank', 'iban' => 'NL00SPT'.str_pad((string) $cpId, 9, '0', STR_PAD_LEFT), 'default_currency' => 'EUR',
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/spt.csv',
        'sha256' => str_pad('spt'.$cpId, 64, 'a', STR_PAD_LEFT), 'uploaded_at' => '2026-05-01 00:00:00', 'status' => 'previewed',
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $txId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId, 'counterparty_id' => $cpId,
        'fingerprint' => str_pad('spt'.$cpId, 64, 'c', STR_PAD_LEFT), 'posted_at' => '2026-05-01',
        'booked_at' => '2026-05-01 00:00:00', 'value_date' => '2026-05-01',
        'amount_minor' => -$monthlyMinor, 'currency' => 'EUR', 'settled_amount_minor' => -$monthlyMinor, 'settled_currency' => 'EUR',
        'counterparty_normalized' => strtolower($merchant), 'counterparty_name' => strtoupper($merchant), 'normalization_version' => 1,
        'type' => 'expense', 'source_format' => 'asn-csv', 'source_row_index' => $cpId,
        'fingerprint_version' => 3, 'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $db->connection()->table('recurring_series_occurrences')->insert([
        'user_id' => $userId, 'recurring_series_id' => $seriesId, 'transaction_id' => $txId,
        'observed_at' => '2026-05-01', 'observed_amount_minor' => -$monthlyMinor, 'observed_currency' => 'EUR',
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);

    return $seriesId;
}

function sptRunJob(User $user): void
{
    /** @var SuppressionEvaluator $suppression */
    $suppression = app(SuppressionEvaluator::class);

    $suppression->suppressDelivery(function () use ($user): void {
        $job = new EmitSavingsPromptsJob($user->id);
        $job->handle(
            app(SavingsInsightsQuery::class),
            app(Dispatcher::class),
        );
    });
}

function sptPromptCount(int $userId): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('notifications')
        ->where('user_id', $userId)
        ->where('trigger_type', NotificationTrigger::SavingsPrompt)
        ->count();
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-18 09:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('emits exactly one prompt for a seeded savings insight', function (): void {
    $user = sptUser('spt-seeded-insight');
    sptChain(app(DatabaseManager::class), $user->id, 'Spotify', 999);

    sptRunJob($user);

    expect(sptPromptCount($user->id))->toBe(1);
});

it('emits nothing for an insight already dismissed in savings_insight_dismissals', function (): void {
    $user = sptUser('spt-dismissed-insight');
    $seriesId = sptChain(app(DatabaseManager::class), $user->id, 'Spotify', 999);

    // Dismissed through the query's own dismiss(): the job must never
    // re-implement that filter.
    app(SavingsInsightsQuery::class)->dismiss($user, 'cheaper:'.$seriesId);

    sptRunJob($user);

    expect(sptPromptCount($user->id))->toBe(0);
});

it('produces still exactly one row when the job runs a second time (stable insight key)', function (): void {
    $user = sptUser('spt-idempotent');
    sptChain(app(DatabaseManager::class), $user->id, 'Spotify', 999);

    sptRunJob($user);
    sptRunJob($user);

    expect(sptPromptCount($user->id))->toBe(1);
});

it('still lands the inbox row with the shipped OFF default, but suppresses delivery with reason trigger_disabled', function (): void {
    $user = sptUser('spt-off-by-default');
    sptChain(app(DatabaseManager::class), $user->id, 'Spotify', 999);

    sptRunJob($user);

    // A disabled toggle means "store but do not push", never a dropped row.
    expect(sptPromptCount($user->id))->toBe(1);

    /** @var SuppressionEvaluator $evaluator */
    $evaluator = app(SuppressionEvaluator::class);
    $decision = $evaluator->shouldDeliver($user->id, NotificationTrigger::SavingsPrompt, CarbonImmutable::now());

    expect($decision->deliver)->toBeFalse();
    expect($decision->reason)->toBe('trigger_disabled');
});

it('never leaks one user\'s savings insight into another user\'s prompts (cross-user)', function (): void {
    $userA = sptUser('spt-cross-a');
    sptChain(app(DatabaseManager::class), $userA->id, 'Spotify', 999);

    $userB = sptUser('spt-cross-b');

    sptRunJob($userA);
    sptRunJob($userB);

    expect(sptPromptCount($userA->id))->toBe(1);
    expect(sptPromptCount($userB->id))->toBe(0);
});
