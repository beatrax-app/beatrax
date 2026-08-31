<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Anomaly\Internal\Enums\AnomalyDetector;
use Modules\Anomaly\Internal\Enums\DismissedAs;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Public\Services\AnomalyAlertQuery;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

function anomQueryUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @return array{0: int, 1: int}
 */
function anomQueryTxn(DatabaseManager $db, int $userId, string $merchantSlug): array
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN test',
        'slug' => 'anomq-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/anomq-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'anomq-run-'.$suffix),
        'uploaded_at' => '2026-06-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $counterpartyId = $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId,
        'type' => 'merchant',
        'slug' => $merchantSlug.'-'.$suffix,
        'display_name' => ucfirst($merchantSlug),
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $txnId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'anomq-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-06-15',
        'booked_at' => '2026-06-15 00:00:00',
        'value_date' => '2026-06-15',
        'amount_minor' => -2349,
        'currency' => 'EUR',
        'settled_amount_minor' => -2349,
        'settled_currency' => 'EUR',
        'counterparty_id' => $counterpartyId,
        'counterparty_normalized' => $merchantSlug,
        'counterparty_name' => strtoupper($merchantSlug),
        'normalization_version' => 1,
        'description' => 'anomq fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    return [$txnId, $counterpartyId];
}

function anomQueryAlert(User $user, string $state, string $merchantSlug = 'spotify', array $overrides = []): AnomalyAlert
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    [$txnId] = anomQueryTxn($db, (int) $user->id, $merchantSlug);

    return AnomalyAlert::factory()->create(array_merge([
        'user_id' => $user->id,
        'transaction_id' => $txnId,
        'state' => $state,
        'direction' => 'expense',
        'reasons' => ['large'],
        'baseline_amount_minor' => -999,
        'latest_amount_minor' => -2349,
        'currency' => 'EUR',
        'sensitivity_percent_used' => 50,
        'detected_at' => CarbonImmutable::parse('2026-06-15 12:00:00'),
    ], $overrides));
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-20 09:00:00');
    $this->user = anomQueryUser('anomq-a');
    $this->other = anomQueryUser('anomq-b');
    $this->query = $this->app->make(AnomalyAlertQuery::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('returns open alerts with the resolved merchant display name', function (): void {
    anomQueryAlert($this->user, 'open', 'spotify');

    $open = $this->query->openForUser($this->user);

    expect($open)->toHaveCount(1)
        ->and($open[0]->state)->toBe('open')
        ->and($open[0]->displayName)->toBe('Spotify')
        ->and($open[0]->reasons)->toBe([AnomalyDetector::Large]);
});

it('treats a snoozed-but-expired alert as open (revival-aware)', function (): void {
    anomQueryAlert($this->user, 'snoozed', 'spotify', [
        'snoozed_until' => CarbonImmutable::parse('2026-06-18 09:00:00'),
    ]);

    expect($this->query->openForUser($this->user))->toHaveCount(1)
        ->and($this->query->openCountForUser($this->user))->toBe(1);
});

it('excludes a still-snoozed alert from the open set', function (): void {
    anomQueryAlert($this->user, 'snoozed', 'spotify', [
        'snoozed_until' => CarbonImmutable::parse('2026-06-25 09:00:00'),
    ]);

    expect($this->query->openForUser($this->user))->toHaveCount(0)
        ->and($this->query->openCountForUser($this->user))->toBe(0);
});

it('lists dismissed alerts via the plain dismissed state', function (): void {
    anomQueryAlert($this->user, 'dismissed', 'spotify', ['dismissed_as' => 'expected']);

    $dismissed = $this->query->dismissedForUser($this->user);

    expect($dismissed)->toHaveCount(1)
        ->and($dismissed[0]->state)->toBe('dismissed')
        ->and($dismissed[0]->dismissedAs)->toBe(DismissedAs::Expected)
        ->and($this->query->openForUser($this->user))->toHaveCount(0);
});

it('lists acknowledged alerts in history', function (): void {
    anomQueryAlert($this->user, 'acknowledged', 'spotify');

    expect($this->query->historyForUser($this->user))->toHaveCount(1)
        ->and($this->query->openForUser($this->user))->toHaveCount(0);
});

it('is user-scoped — another user sees nothing', function (): void {
    anomQueryAlert($this->user, 'open', 'spotify');

    expect($this->query->openForUser($this->other))->toHaveCount(0)
        ->and($this->query->openCountForUser($this->other))->toBe(0);
});

it('counts open alerts per detector reason for the dashboard breakdown', function (): void {
    anomQueryAlert($this->user, 'open', 'spotify', ['reasons' => ['large', 'first_time']]);
    anomQueryAlert($this->user, 'open', 'netflix', ['reasons' => ['large']]);

    $breakdown = $this->query->openDetectorBreakdownForUser($this->user);

    expect($breakdown['large'])->toBe(2)
        ->and($breakdown['first_time'])->toBe(1);
});
