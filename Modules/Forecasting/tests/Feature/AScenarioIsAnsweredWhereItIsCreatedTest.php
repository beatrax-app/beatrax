<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Http\Livewire\ForecastPage;
use Modules\Forecasting\Internal\Pipeline\ProjectionPipeline;
use Modules\Forecasting\Internal\Support\ForecastChartView;
use Modules\Forecasting\Public\Actions\AddScenarioMutation;
use Modules\Forecasting\Public\Actions\CreateScenario;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\CancelSeriesPayload;
use Modules\Forecasting\Public\Enums\ScenarioMutationKind;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use Modules\Recurring\Public\Enums\SeriesCadence;

uses(RefreshDatabase::class);

/** @link ../../../../.docs/features/forecasting/architecture.md */
const SAC_TODAY = '2026-08-23';

const SAC_HORIZON_DAYS = 30;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(SAC_TODAY.' 09:00:00');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = User::query()->create([
        'username' => 'sac',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
        'default_currency_view' => 'eur_only',
    ]);
    $this->accountId = $this->db->connection()->table('accounts')->insertGetId([
        'user_id' => $this->user->id,
        'name' => 'SAC Bank',
        'slug' => 'sac-'.bin2hex(random_bytes(4)),
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL00SAC'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => Currency::Eur->value,
        'starting_balance_minor' => 500_000,
        'starting_balance_date' => '2026-01-01',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
    $this->seriesId = $this->db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $this->user->id,
        'direction' => 'expense',
        'detected_name' => 'SAC Rent',
        'state' => RecurringSeriesState::Approved->value,
        'cadence' => SeriesCadence::Monthly->value,
        'latest_amount_minor' => -120_000,
        'latest_currency' => Currency::Eur->value,
        'monthly_equivalent_minor' => -120_000,
        'variance_tolerance_percent' => 5,
        'cluster_key' => 'sac::'.bin2hex(random_bytes(4)),
        'cluster_counterparty_key' => 'sac-rent',
        'next_expected_at' => '2026-09-01',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

// "Adding a scenario just lets you add one and fill the name, but literally
// nothing else is different." The tab a reader lands on is All accounts, and
// every scenario surface hung off a per-account chart they had not opened, so
// creating one there changed nothing on screen.
it('shows the scenario roll-up and its editor on the tab the reader lands on', function (): void {
    app(ProjectionPipeline::class)->project($this->user, null, SAC_HORIZON_DAYS);

    $component = Livewire::actingAs($this->user)
        ->test(ForecastPage::class)
        ->call('startCreateScenario')
        ->set('newScenarioName', 'Cancel the rent')
        ->call('saveNewScenario');

    $html = $component->html();

    expect($html)->toContain('data-testid="all-accounts-aggregate-scenario-chart"')
        ->and($html)->toContain('wire:click="startAddMutation"');
});

it('redraws the all-accounts roll-up once a what-if is added to the scenario', function (): void {
    $scenarioId = (app(CreateScenario::class))($this->user, 'Cancel the rent');
    (app(AddScenarioMutation::class))(
        $scenarioId,
        $this->user,
        ScenarioMutationKind::CancelSeries->value,
        new CancelSeriesPayload(seriesId: $this->seriesId),
    );

    app(ProjectionPipeline::class)->project($this->user, null, SAC_HORIZON_DAYS);
    app(ProjectionPipeline::class)->project($this->user, $scenarioId, SAC_HORIZON_DAYS);

    /** @var ForecastChartView $charts */
    $charts = app(ForecastChartView::class);
    $aggregate = $charts->aggregate(
        $charts->accountList($this->user),
        SAC_HORIZON_DAYS,
        $this->user,
        Currency::Eur->value,
        $scenarioId,
    );

    $horizonEnd = static fn (array $points): int => $points[count($points) - 1]['point_minor'];

    expect($aggregate['aggregateScenarioPoints'])->not->toBe([])
        ->and($horizonEnd($aggregate['aggregateScenarioPoints']))
        ->toBe($horizonEnd($aggregate['aggregatePoints']) + 120_000);
});
