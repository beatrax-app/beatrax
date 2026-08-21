<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Forecasting\Models\ForecastScenario;
use Modules\Forecasting\Models\ForecastScenarioMutation;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddOneOffPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddRecurringPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\CancelSeriesPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ChangeSeriesAmountPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ShiftSeriesDatePayload;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->user = User::query()->create([
        'username' => 'forecast-cast',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $this->scenario = ForecastScenario::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Cast test scenario',
    ]);
});

it('round-trips CancelSeriesPayload through the cast', function (): void {
    $mutation = ForecastScenarioMutation::factory()
        ->cancelSeries(seriesId: 99)
        ->create([
            'user_id' => $this->user->id,
            'forecast_scenario_id' => $this->scenario->id,
        ]);

    $fresh = ForecastScenarioMutation::query()->findOrFail($mutation->id);

    expect($fresh->payload)->toBeInstanceOf(CancelSeriesPayload::class);
    /** @var CancelSeriesPayload $payload */
    $payload = $fresh->payload;
    expect($payload->seriesId)->toBe(99);
    expect($payload->kind())->toBe('cancel_series');
    expect($fresh->kind)->toBe('cancel_series');
});

it('round-trips AddOneOffPayload through the cast', function (): void {
    $mutation = ForecastScenarioMutation::factory()
        ->addOneOff(
            date: '2026-06-15',
            amountMinor: -7500,
            currency: 'EUR',
            direction: 'expense',
        )
        ->create([
            'user_id' => $this->user->id,
            'forecast_scenario_id' => $this->scenario->id,
        ]);

    $fresh = ForecastScenarioMutation::query()->findOrFail($mutation->id);

    expect($fresh->payload)->toBeInstanceOf(AddOneOffPayload::class);
    /** @var AddOneOffPayload $payload */
    $payload = $fresh->payload;
    expect($payload->date)->toBe('2026-06-15');
    expect($payload->amountMinor)->toBe(-7500);
    expect($payload->currency)->toBe('EUR');
    expect($payload->direction)->toBe('expense');
    expect($payload->kind())->toBe('add_one_off');
});

it('round-trips AddRecurringPayload through the cast', function (): void {
    $mutation = ForecastScenarioMutation::factory()
        ->addRecurring(
            startDate: '2026-07-01',
            amountMinor: -1499,
            currency: 'USD',
            direction: 'expense',
            cadence: 'monthly',
        )
        ->create([
            'user_id' => $this->user->id,
            'forecast_scenario_id' => $this->scenario->id,
        ]);

    $fresh = ForecastScenarioMutation::query()->findOrFail($mutation->id);

    expect($fresh->payload)->toBeInstanceOf(AddRecurringPayload::class);
    /** @var AddRecurringPayload $payload */
    $payload = $fresh->payload;
    expect($payload->startDate)->toBe('2026-07-01');
    expect($payload->amountMinor)->toBe(-1499);
    expect($payload->currency)->toBe('USD');
    expect($payload->cadence)->toBe('monthly');
    expect($payload->kind())->toBe('add_recurring');
});

it('round-trips ChangeSeriesAmountPayload through the cast', function (): void {
    $mutation = ForecastScenarioMutation::factory()
        ->changeSeriesAmount(seriesId: 17, newAmountMinor: -2500)
        ->create([
            'user_id' => $this->user->id,
            'forecast_scenario_id' => $this->scenario->id,
        ]);

    $fresh = ForecastScenarioMutation::query()->findOrFail($mutation->id);

    expect($fresh->payload)->toBeInstanceOf(ChangeSeriesAmountPayload::class);
    /** @var ChangeSeriesAmountPayload $payload */
    $payload = $fresh->payload;
    expect($payload->seriesId)->toBe(17);
    expect($payload->newAmountMinor)->toBe(-2500);
    expect($payload->kind())->toBe('change_series_amount');
});

it('round-trips ShiftSeriesDatePayload through the cast', function (): void {
    $mutation = ForecastScenarioMutation::factory()
        ->shiftSeriesDate(seriesId: 8, newNextDate: '2026-08-15', scope: 'all_subsequent')
        ->create([
            'user_id' => $this->user->id,
            'forecast_scenario_id' => $this->scenario->id,
        ]);

    $fresh = ForecastScenarioMutation::query()->findOrFail($mutation->id);

    expect($fresh->payload)->toBeInstanceOf(ShiftSeriesDatePayload::class);
    /** @var ShiftSeriesDatePayload $payload */
    $payload = $fresh->payload;
    expect($payload->seriesId)->toBe(8);
    expect($payload->newNextDate)->toBe('2026-08-15');
    expect($payload->scope)->toBe('all_subsequent');
    expect($payload->kind())->toBe('shift_series_date');
});

it('throws InvalidArgumentException when the row\'s kind is unknown', function (): void {
    // The schema triggers would reject 'foo' at INSERT, which is what
    // MigrationsTest covers; dropping them isolates the cast's own branch.
    $this->db->connection()->statement('DROP TRIGGER IF EXISTS forecast_scenario_mutations_kind_insert_check');
    $this->db->connection()->statement('DROP TRIGGER IF EXISTS forecast_scenario_mutations_kind_update_check');

    $rowId = $this->db->connection()->table('forecast_scenario_mutations')->insertGetId([
        'user_id' => $this->user->id,
        'forecast_scenario_id' => $this->scenario->id,
        'kind' => 'foo',
        'target_series_id' => null,
        'payload' => json_encode(['unrelated' => true], JSON_THROW_ON_ERROR),
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    expect(fn () => ForecastScenarioMutation::query()->findOrFail($rowId)->payload)
        ->toThrow(InvalidArgumentException::class, 'Unknown scenario mutation kind: foo');
});

it('throws InvalidArgumentException on kind/payload mismatch at assignment time', function (): void {
    // set() runs at assignment, not at save(), so the mismatch is caught
    // before any SQL is issued.
    $mutation = new ForecastScenarioMutation;
    $mutation->user_id = $this->user->id;
    $mutation->forecast_scenario_id = $this->scenario->id;
    $mutation->kind = 'cancel_series';
    $mutation->target_series_id = 99;

    $assign = function () use ($mutation): void {
        $mutation->payload = new AddOneOffPayload(
            date: '2026-06-01',
            amountMinor: -100,
            currency: 'EUR',
            direction: 'expense',
        );
    };

    expect($assign)->toThrow(
        InvalidArgumentException::class,
        "ScenarioMutationPayloadCast: payload kind 'add_one_off' does not match row kind 'cancel_series'.",
    );
});

it('throws InvalidArgumentException when the payload is assigned before the kind column', function (): void {
    $mutation = new ForecastScenarioMutation;

    $assign = function () use ($mutation): void {
        $mutation->payload = new CancelSeriesPayload(seriesId: 1);
    };

    expect($assign)->toThrow(InvalidArgumentException::class);
});

it('throws InvalidArgumentException when the payload column does not decode to an array', function (): void {
    $mutation = ForecastScenarioMutation::factory()
        ->cancelSeries(seriesId: 5)
        ->create([
            'user_id' => $this->user->id,
            'forecast_scenario_id' => $this->scenario->id,
        ]);

    $this->db->connection()->table('forecast_scenario_mutations')
        ->where('id', $mutation->id)
        ->update(['payload' => json_encode('not-an-object', JSON_THROW_ON_ERROR)]);

    expect(fn () => ForecastScenarioMutation::query()->findOrFail($mutation->id)->payload)
        ->toThrow(InvalidArgumentException::class, 'payload JSON did not decode to an array');
});

it('every factory state method produces a row whose payload round-trips through the cast', function (): void {
    $kinds = [
        'cancel_series' => ForecastScenarioMutation::factory()->cancelSeries(seriesId: 1),
        'add_one_off' => ForecastScenarioMutation::factory()->addOneOff(
            date: '2026-06-01',
            amountMinor: -100,
            currency: 'EUR',
            direction: 'expense',
        ),
        'add_recurring' => ForecastScenarioMutation::factory()->addRecurring(
            startDate: '2026-06-01',
            amountMinor: -100,
            currency: 'EUR',
            direction: 'expense',
            cadence: 'monthly',
        ),
        'change_series_amount' => ForecastScenarioMutation::factory()->changeSeriesAmount(
            seriesId: 1,
            newAmountMinor: -100,
        ),
        'shift_series_date' => ForecastScenarioMutation::factory()->shiftSeriesDate(
            seriesId: 1,
            newNextDate: '2026-06-01',
            scope: 'next',
        ),
    ];

    foreach ($kinds as $expectedKind => $factory) {
        $mutation = $factory->create([
            'user_id' => $this->user->id,
            'forecast_scenario_id' => $this->scenario->id,
        ]);
        $fresh = ForecastScenarioMutation::query()->findOrFail($mutation->id);
        expect($fresh->kind)->toBe($expectedKind);
        expect($fresh->payload->kind())->toBe($expectedKind);
    }
});
