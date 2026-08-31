<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Pipeline\ProjectionPipeline;
use Modules\Forecasting\Internal\Support\ForecastChartView;
use Modules\Forecasting\Public\Events\ForecastShortfallDetected;
use Modules\Forecasting\Public\Services\ForecastHighlightsQuery;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\TransactionType;

uses(RefreshDatabase::class);

// A credit card's balance is what is owed, so it is below any floor the reader
// can set for the card's whole life. Judged against the zero-crossing default
// it produced a permanent cash-flow shortfall: eight events out of one baseline
// sweep on the shipped demo seed, captioned "below your EUR0.00 buffer" beside a
// chip reading "Buffer: not set", on a chart that never drew the band.

const CDN_HORIZON = 30;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-23 09:00:00');
    $this->db = app(DatabaseManager::class);
    $this->user = User::query()->create([
        'username' => 'cdn',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
        'default_currency_view' => 'eur_only',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function cdnAccount(DatabaseManager $db, int $userId, AccountKind $kind, array $overrides = []): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId(array_merge([
        'user_id' => $userId,
        'name' => $kind === AccountKind::IcsCard ? 'ICS Card' : 'ASN Betaalrekening',
        'slug' => 'cdn-'.$hex,
        'kind' => $kind->value,
        'iban' => 'NL00CDN'.strtoupper($hex),
        'default_currency' => Currency::Eur->value,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ], $overrides));
}

function cdnRow(DatabaseManager $db, int $userId, int $accountId, string $postedAt, int $minor): void
{
    static $row = 0;
    $row++;
    $hex = bin2hex(random_bytes(6));

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/cdn-'.$hex.'.csv',
        'sha256' => hash('sha256', 'cdn-'.$hex),
        'uploaded_at' => '2026-01-01 00:00:00',
        'status' => 'imported',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $db->connection()->table('transactions')->insert([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'cdn-fp-'.$hex),
        'fingerprint_version' => 3,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $minor,
        'currency' => Currency::Eur->value,
        'settled_amount_minor' => $minor,
        'settled_currency' => Currency::Eur->value,
        'counterparty_normalized' => 'cdn',
        'counterparty_name' => 'CDN',
        'normalization_version' => 1,
        'description' => 'cdn fixture',
        'type' => $minor >= 0 ? TransactionType::Income->value : TransactionType::Expense->value,
        'source_format' => 'asn-csv',
        'source_row_index' => $row,
        'status' => ClearedStatus::Cleared->value,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

it('raises no shortfall for an ordinary outstanding card balance', function (): void {
    Event::fake([ForecastShortfallDetected::class]);

    $cardId = cdnAccount($this->db, $this->user->id, AccountKind::IcsCard);
    cdnRow($this->db, $this->user->id, $cardId, '2026-07-04', -55_400);

    app(ProjectionPipeline::class)->project($this->user, null, CDN_HORIZON);

    expect($this->db->connection()->table('forecast_shortfall_windows')->where('account_id', $cardId)->count())->toBe(0)
        ->and(app(ForecastHighlightsQuery::class)->activeShortfallCountForUser($this->user))->toBe(0);

    Event::assertNotDispatched(ForecastShortfallDetected::class);
});

// The whole point is not to stop detecting: a cash account crossing zero is a
// shortfall and still has to be raised.
it('still raises a shortfall for a bank account crossing the zero-crossing default', function (): void {
    $bankId = cdnAccount($this->db, $this->user->id, AccountKind::Bank);
    cdnRow($this->db, $this->user->id, $bankId, '2026-07-04', -55_400);

    app(ProjectionPipeline::class)->project($this->user, null, CDN_HORIZON);

    expect($this->db->connection()->table('forecast_shortfall_windows')->where('account_id', $bankId)->count())->toBe(1);
});

// A buffer the reader typed is a question this can still answer: tell me when
// what I owe on the card passes this figure.
it('honours a buffer the reader set on a card', function (): void {
    $cardId = cdnAccount($this->db, $this->user->id, AccountKind::IcsCard, ['forecast_min_buffer_minor' => 0]);
    cdnRow($this->db, $this->user->id, $cardId, '2026-07-04', -55_400);

    app(ProjectionPipeline::class)->project($this->user, null, CDN_HORIZON);

    expect($this->db->connection()->table('forecast_shortfall_windows')->where('account_id', $cardId)->count())->toBe(1);
});

// The caption "below your EUR0.00 buffer" and a chart with no band drawn were
// the same state: the band was drawn only where the READER had set a buffer,
// never where the zero-crossing default was doing the judging.
it('draws the floor band on a bank account whose buffer the reader never set', function (): void {
    $bankId = cdnAccount($this->db, $this->user->id, AccountKind::Bank);
    cdnRow($this->db, $this->user->id, $bankId, '2026-07-04', -55_400);

    app(ProjectionPipeline::class)->project($this->user, null, CDN_HORIZON);

    $view = app(ForecastChartView::class)->selectedAccount(
        $bankId,
        CDN_HORIZON,
        null,
        $this->user,
        Currency::Eur->value,
    );

    expect($view['effectiveBufferMinor'])->toBeNull()
        ->and($view['shortfallWindows'])->not->toBeEmpty()
        ->and($view['apexOptions']['annotations']['yaxis'])->not->toBe([]);
});

it('draws no floor band on a card, which is the state that raises no caption', function (): void {
    $cardId = cdnAccount($this->db, $this->user->id, AccountKind::IcsCard);
    cdnRow($this->db, $this->user->id, $cardId, '2026-07-04', -55_400);

    app(ProjectionPipeline::class)->project($this->user, null, CDN_HORIZON);

    $view = app(ForecastChartView::class)->selectedAccount(
        $cardId,
        CDN_HORIZON,
        null,
        $this->user,
        Currency::Eur->value,
    );

    expect($view['shortfallWindows'])->toBe([])
        ->and($view['apexOptions']['annotations']['yaxis'])->toBe([]);
});
