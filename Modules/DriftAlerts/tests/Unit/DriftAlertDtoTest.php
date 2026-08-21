<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Public\Dto\CancellationImpactDto;
use Modules\DriftAlerts\Public\Dto\DriftAlertDto;
use Modules\Ledger\Public\ValueObjects\Money;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->user = User::query()->create([
        'username' => 'drift-dto',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $this->seriesId = $this->db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $this->user->id,
        'direction' => 'expense',
        'detected_name' => 'Spotify',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1149,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'spotify|monthly|EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $this->occurrenceId = $this->db->connection()->table('recurring_series_occurrences')->insertGetId([
        'user_id' => $this->user->id,
        'recurring_series_id' => $this->seriesId,
        'transaction_id' => seedDriftAlertDtoTransaction($this->db, $this->user->id),
        'observed_at' => '2026-05-15',
        'observed_amount_minor' => -1149,
        'observed_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
});

it('constructs a DriftAlertDto in the EUR-only scenario with a null eurEquivalent', function (): void {
    $dto = new DriftAlertDto(
        driftAlertId: 7,
        recurringSeriesId: $this->seriesId,
        direction: 'expense',
        displayName: 'Spotify',
        state: 'open',
        baselineAmount: Money::ofMinor(-999, 'EUR'),
        latestAmount: Money::ofMinor(-1149, 'EUR'),
        delta: Money::ofMinor(-150, 'EUR'),
        annualizedImpact: Money::ofMinor(-1800, 'EUR'),
        eurEquivalent: null,
        thresholdPercentUsed: 5,
        thresholdSource: 'global',
        detectedAt: CarbonImmutable::parse('2026-05-19 12:00:00'),
        actionedAt: null,
        snoozedUntil: null,
    );

    expect($dto->driftAlertId)->toBe(7);
    expect($dto->recurringSeriesId)->toBe($this->seriesId);
    expect($dto->direction)->toBe('expense');
    expect($dto->displayName)->toBe('Spotify');
    expect($dto->state)->toBe('open');
    expect($dto->baselineAmount)->toBeInstanceOf(Money::class);
    expect($dto->baselineAmount->toMinor())->toBe(-999);
    expect($dto->baselineAmount->currency())->toBe('EUR');
    expect($dto->latestAmount->toMinor())->toBe(-1149);
    expect($dto->delta->toMinor())->toBe(-150);
    expect($dto->annualizedImpact->toMinor())->toBe(-1800);
    expect($dto->eurEquivalent)->toBeNull();
    expect($dto->thresholdPercentUsed)->toBe(5);
    expect($dto->thresholdSource)->toBe('global');
    expect($dto->detectedAt)->toBeInstanceOf(CarbonImmutable::class);
    expect($dto->actionedAt)->toBeNull();
    expect($dto->snoozedUntil)->toBeNull();
});

it('constructs a DriftAlertDto in the USD scenario with a non-null eurEquivalent', function (): void {
    $dto = new DriftAlertDto(
        driftAlertId: 11,
        recurringSeriesId: $this->seriesId,
        direction: 'expense',
        displayName: 'Google Play Family',
        state: 'open',
        baselineAmount: Money::ofMinor(-999, 'USD'),
        latestAmount: Money::ofMinor(-1149, 'USD'),
        delta: Money::ofMinor(-150, 'USD'),
        annualizedImpact: Money::ofMinor(-1800, 'USD'),
        eurEquivalent: Money::ofMinor(-1670, 'EUR'),
        thresholdPercentUsed: 8,
        thresholdSource: 'series_override',
        detectedAt: CarbonImmutable::parse('2026-05-19 12:00:00'),
        actionedAt: CarbonImmutable::parse('2026-05-19 12:34:56'),
        snoozedUntil: null,
    );

    expect($dto->baselineAmount->currency())->toBe('USD');
    expect($dto->latestAmount->currency())->toBe('USD');
    expect($dto->eurEquivalent)->toBeInstanceOf(Money::class);
    expect($dto->eurEquivalent?->toMinor())->toBe(-1670);
    expect($dto->eurEquivalent?->currency())->toBe('EUR');
    expect($dto->thresholdSource)->toBe('series_override');
    expect($dto->actionedAt)->toBeInstanceOf(CarbonImmutable::class);
});

it('refuses to reassign a readonly property on DriftAlertDto', function (): void {
    $dto = new DriftAlertDto(
        driftAlertId: 7,
        recurringSeriesId: $this->seriesId,
        direction: 'expense',
        displayName: 'Spotify',
        state: 'open',
        baselineAmount: Money::ofMinor(-999, 'EUR'),
        latestAmount: Money::ofMinor(-1149, 'EUR'),
        delta: Money::ofMinor(-150, 'EUR'),
        annualizedImpact: Money::ofMinor(-1800, 'EUR'),
        eurEquivalent: null,
        thresholdPercentUsed: 5,
        thresholdSource: 'global',
        detectedAt: CarbonImmutable::parse('2026-05-19 12:00:00'),
        actionedAt: null,
        snoozedUntil: null,
    );

    expect(static function () use ($dto): void {
        $dto->driftAlertId = 9;
    })->toThrow(Error::class);
});

it('hydrates a DriftAlert factory row and projects it into a DriftAlertDto round-trip', function (): void {
    $alert = DriftAlert::factory()->create([
        'user_id' => $this->user->id,
        'recurring_series_id' => $this->seriesId,
        'state' => 'open',
        'direction' => 'expense',
        'baseline_amount_minor' => -999,
        'latest_amount_minor' => -1149,
        'currency' => 'EUR',
        'delta_minor' => -150,
        'annualized_impact_minor' => -1800,
        'threshold_percent_used' => 5,
        'threshold_source' => 'global',
        'latest_occurrence_id' => $this->occurrenceId,
        'detected_at' => CarbonImmutable::parse('2026-05-19 12:00:00'),
    ]);

    expect($alert->state)->toBe('open');
    expect($alert->baseline_amount_minor)->toBe(-999);
    expect($alert->detected_at)->toBeInstanceOf(CarbonImmutable::class);

    $currency = $alert->currency;
    $dto = new DriftAlertDto(
        driftAlertId: (int) $alert->id,
        recurringSeriesId: (int) $alert->recurring_series_id,
        direction: $alert->direction,
        displayName: 'Spotify',
        state: $alert->state,
        baselineAmount: Money::ofMinor($alert->baseline_amount_minor, $currency),
        latestAmount: Money::ofMinor($alert->latest_amount_minor, $currency),
        delta: Money::ofMinor($alert->delta_minor, $currency),
        annualizedImpact: Money::ofMinor($alert->annualized_impact_minor, $currency),
        eurEquivalent: null,
        thresholdPercentUsed: $alert->threshold_percent_used,
        thresholdSource: $alert->threshold_source,
        detectedAt: $alert->detected_at,
        actionedAt: $alert->actioned_at,
        snoozedUntil: $alert->snoozed_until,
    );

    expect($dto->driftAlertId)->toBe((int) $alert->id);
    expect($dto->latestAmount->toMinor())->toBe(-1149);
    expect($dto->latestAmount->currency())->toBe('EUR');
    expect($dto->delta->toMinor())->toBe(-150);
    expect($dto->thresholdSource)->toBe('global');
});

it('constructs a CancellationImpactDto and round-trips every readonly property', function (): void {
    $dto = new CancellationImpactDto(
        recurringSeriesId: $this->seriesId,
        monthlySavings: Money::ofMinor(1149, 'EUR'),
        annualSavings: Money::ofMinor(13788, 'EUR'),
        currency: 'EUR',
    );

    expect($dto->recurringSeriesId)->toBe($this->seriesId);
    expect($dto->monthlySavings)->toBeInstanceOf(Money::class);
    expect($dto->monthlySavings->toMinor())->toBe(1149);
    expect($dto->annualSavings->toMinor())->toBe(13788);
    expect($dto->currency)->toBe('EUR');

    expect(static function () use ($dto): void {
        $dto->currency = 'USD';
    })->toThrow(Error::class);
});

it('carries a non-EUR currency on CancellationImpactDto for cross-currency series', function (): void {
    $dto = new CancellationImpactDto(
        recurringSeriesId: $this->seriesId,
        monthlySavings: Money::ofMinor(999, 'USD'),
        annualSavings: Money::ofMinor(11988, 'USD'),
        currency: 'USD',
    );

    expect($dto->currency)->toBe('USD');
    expect($dto->monthlySavings->currency())->toBe('USD');
    expect($dto->annualSavings->currency())->toBe('USD');
});

it('exposes the three DriftAlert relations as BelongsTo and HasMany', function (): void {
    $reflection = new ReflectionClass(DriftAlert::class);
    expect($reflection->hasMethod('recurringSeries'))->toBeTrue();
    expect($reflection->hasMethod('latestOccurrence'))->toBeTrue();
    expect($reflection->hasMethod('transitions'))->toBeTrue();

    expect((new DriftAlert)->getTable())->toBe('drift_alerts');
});

function seedDriftAlertDtoTransaction(DatabaseManager $db, int $userId): int
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN test',
        'slug' => 'drift-dto-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB',
        'default_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/drift-dto.csv',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => '2026-05-19 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => str_repeat('c', 64),
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 00:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => -1149,
        'currency' => 'EUR',
        'settled_amount_minor' => -1149,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'spotify',
        'counterparty_name' => 'SPOTIFY',
        'normalization_version' => 1,
        'description' => 'drift dto fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
}
