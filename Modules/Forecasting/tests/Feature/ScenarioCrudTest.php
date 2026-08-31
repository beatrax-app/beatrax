<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Modules\Forecasting\Internal\Listeners\ProjectForecastOnScenarioChange;
use Modules\Forecasting\Internal\Pipeline\ForecastContribution;
use Modules\Forecasting\Internal\Pipeline\ScenarioApplier;
use Modules\Forecasting\Models\ForecastScenario;
use Modules\Forecasting\Public\Actions\AddScenarioMutation;
use Modules\Forecasting\Public\Actions\CreateScenario;
use Modules\Forecasting\Public\Actions\DeleteScenario;
use Modules\Forecasting\Public\Actions\EditScenarioMutation;
use Modules\Forecasting\Public\Actions\RemoveScenarioMutation;
use Modules\Forecasting\Public\Actions\RenameScenario;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddOneOffPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\CancelSeriesPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ChangeSeriesAmountPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ShiftSeriesDatePayload;
use Modules\Forecasting\Public\Events\ScenarioCreated;
use Modules\Forecasting\Public\Events\ScenarioDeleted;
use Modules\Forecasting\Public\Events\ScenarioMutated;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

/** @link ../../../../.docs/features/forecasting/scenario-isolation.md */
function scUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function scSeriesId(DatabaseManager $db, int $userId, string $name = 'Netflix', int $amountMinor = -1199, string $cadence = 'monthly'): int
{
    return (int) $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId,
        'direction' => $amountMinor < 0 ? 'expense' : 'income',
        'detected_name' => $name,
        'state' => 'approved',
        'cadence' => $cadence,
        'latest_amount_minor' => $amountMinor,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => $amountMinor,
        'variance_tolerance_percent' => 5,
        'next_expected_at' => '2026-05-25',
        'cluster_key' => 'cluster-'.$name.'-'.$userId,
        'cluster_counterparty_key' => $name,
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

beforeEach(function (): void {
    // Every fixture below is dated in May 2026 and the write-side now refuses a
    // date no horizon reaches, so the clock has to be where the fixtures are.
    CarbonImmutable::setTestNow('2026-05-19 09:00:00');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = scUser('sc-user');
    $this->other = scUser('sc-other');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

it('1. CreateScenario happy path: returns the new id + persists row + dispatches ScenarioCreated', function (): void {
    Event::fake([ScenarioCreated::class]);
    /** @var CreateScenario $action */
    $action = $this->app->make(CreateScenario::class);

    $id = ($action)($this->user, 'Tighten subscriptions');

    expect($id)->toBeGreaterThan(0);
    $row = $this->db->connection()->table('forecast_scenarios')->where('id', $id)->first();
    expect($row)->not->toBeNull();
    expect($row->user_id)->toBe($this->user->id);
    expect($row->name)->toBe('Tighten subscriptions');
    Event::assertDispatched(ScenarioCreated::class, fn (ScenarioCreated $e): bool => $e->scenarioId === $id && $e->userId === $this->user->id);
});

it('2. CreateScenario duplicate-name throws InvalidArgumentException with UI-SPEC copy', function (): void {
    /** @var CreateScenario $action */
    $action = $this->app->make(CreateScenario::class);
    ($action)($this->user, 'Tighten subscriptions');

    expect(fn () => ($action)($this->user, 'Tighten subscriptions'))
        ->toThrow(InvalidArgumentException::class, 'A scenario with that name already exists.');
});

it('CreateScenario rejects a name longer than the max length', function (): void {
    /** @var CreateScenario $action */
    $action = $this->app->make(CreateScenario::class);
    $tooLong = str_repeat('a', ForecastScenario::MAX_NAME_LENGTH + 1);

    expect(fn () => ($action)($this->user, $tooLong))
        ->toThrow(InvalidArgumentException::class, 'Scenario name must be 120 characters or fewer.');
});

it('RenameScenario rejects a name longer than the max length', function (): void {
    /** @var CreateScenario $create */
    $create = $this->app->make(CreateScenario::class);
    /** @var RenameScenario $rename */
    $rename = $this->app->make(RenameScenario::class);
    $id = ($create)($this->user, 'Fits fine');
    $tooLong = str_repeat('a', ForecastScenario::MAX_NAME_LENGTH + 1);

    expect(fn () => ($rename)($id, $this->user, $tooLong))
        ->toThrow(InvalidArgumentException::class, 'Scenario name must be 120 characters or fewer.');
});

it('3. RenameScenario happy path: persists new name + dispatches ScenarioMutated', function (): void {
    Event::fake([ScenarioMutated::class]);
    /** @var CreateScenario $create */
    $create = $this->app->make(CreateScenario::class);
    /** @var RenameScenario $rename */
    $rename = $this->app->make(RenameScenario::class);

    $id = ($create)($this->user, 'Old name');
    ($rename)($id, $this->user, 'New name');

    expect($this->db->connection()->table('forecast_scenarios')->where('id', $id)->value('name'))->toBe('New name');
    Event::assertDispatched(ScenarioMutated::class, fn (ScenarioMutated $e): bool => $e->scenarioId === $id && $e->kind === 'rename' && $e->mutationId === 0);
});

it('4. RenameScenario cross-user 404 throws NotFoundHttpException', function (): void {
    /** @var CreateScenario $create */
    $create = $this->app->make(CreateScenario::class);
    /** @var RenameScenario $rename */
    $rename = $this->app->make(RenameScenario::class);

    $id = ($create)($this->user, 'Owned by user A');

    expect(fn () => ($rename)($id, $this->other, 'Hijacked'))
        ->toThrow(NotFoundHttpException::class);
});

it('5. DeleteScenario happy path: removes + cascades + dispatches ScenarioDeleted', function (): void {
    Event::fake([ScenarioDeleted::class]);
    /** @var CreateScenario $create */
    $create = $this->app->make(CreateScenario::class);
    /** @var AddScenarioMutation $add */
    $add = $this->app->make(AddScenarioMutation::class);
    /** @var DeleteScenario $delete */
    $delete = $this->app->make(DeleteScenario::class);

    $seriesId = scSeriesId($this->db, $this->user->id);
    $scenarioId = ($create)($this->user, 'To be deleted');
    ($add)($scenarioId, $this->user, 'cancel_series', new CancelSeriesPayload(seriesId: $seriesId));

    expect($this->db->connection()->table('forecast_scenario_mutations')->where('forecast_scenario_id', $scenarioId)->count())->toBe(1);

    ($delete)($scenarioId, $this->user);

    expect($this->db->connection()->table('forecast_scenarios')->where('id', $scenarioId)->count())->toBe(0);
    expect($this->db->connection()->table('forecast_scenario_mutations')->where('forecast_scenario_id', $scenarioId)->count())->toBe(0);
    Event::assertDispatched(ScenarioDeleted::class, fn (ScenarioDeleted $e): bool => $e->scenarioId === $scenarioId);
});

it('6. AddScenarioMutation cancel_series: persists typed payload + dispatches ScenarioMutated', function (): void {
    Event::fake([ScenarioMutated::class]);
    /** @var CreateScenario $create */
    $create = $this->app->make(CreateScenario::class);
    /** @var AddScenarioMutation $add */
    $add = $this->app->make(AddScenarioMutation::class);

    $seriesId = scSeriesId($this->db, $this->user->id);
    $scenarioId = ($create)($this->user, 'Cancel scenario');
    $mutationId = ($add)($scenarioId, $this->user, 'cancel_series', new CancelSeriesPayload(seriesId: $seriesId));

    $row = $this->db->connection()->table('forecast_scenario_mutations')->where('id', $mutationId)->first();
    expect($row)->not->toBeNull();
    expect($row->kind)->toBe('cancel_series');
    expect($row->target_series_id)->toBe($seriesId);
    expect(json_decode((string) $row->payload, true)['seriesId'])->toBe($seriesId);
    Event::assertDispatched(ScenarioMutated::class, fn (ScenarioMutated $e): bool => $e->kind === 'cancel_series' && $e->mutationId === $mutationId);
});

it('7. AddScenarioMutation series-not-owned-by-user throws NotFoundHttpException', function (): void {
    /** @var CreateScenario $create */
    $create = $this->app->make(CreateScenario::class);
    /** @var AddScenarioMutation $add */
    $add = $this->app->make(AddScenarioMutation::class);

    $otherSeriesId = scSeriesId($this->db, $this->other->id);
    $scenarioId = ($create)($this->user, 'Spy scenario');

    expect(fn () => ($add)($scenarioId, $this->user, 'cancel_series', new CancelSeriesPayload(seriesId: $otherSeriesId)))
        ->toThrow(NotFoundHttpException::class, 'Recurring series not found.');
});

it('8. AddScenarioMutation kind/payload mismatch throws before persisting', function (): void {
    /** @var CreateScenario $create */
    $create = $this->app->make(CreateScenario::class);
    /** @var AddScenarioMutation $add */
    $add = $this->app->make(AddScenarioMutation::class);

    $seriesId = scSeriesId($this->db, $this->user->id);
    $scenarioId = ($create)($this->user, 'Mismatch scenario');

    expect(fn () => ($add)($scenarioId, $this->user, 'add_one_off', new CancelSeriesPayload(seriesId: $seriesId)))
        ->toThrow(InvalidArgumentException::class);
    expect($this->db->connection()->table('forecast_scenario_mutations')->where('forecast_scenario_id', $scenarioId)->count())->toBe(0);
});

it('9. RemoveScenarioMutation happy path: removes + dispatches ScenarioMutated', function (): void {
    Event::fake([ScenarioMutated::class]);
    /** @var CreateScenario $create */
    $create = $this->app->make(CreateScenario::class);
    /** @var AddScenarioMutation $add */
    $add = $this->app->make(AddScenarioMutation::class);
    /** @var RemoveScenarioMutation $remove */
    $remove = $this->app->make(RemoveScenarioMutation::class);

    $seriesId = scSeriesId($this->db, $this->user->id);
    $scenarioId = ($create)($this->user, 'Remove scenario');
    $mutationId = ($add)($scenarioId, $this->user, 'cancel_series', new CancelSeriesPayload(seriesId: $seriesId));

    ($remove)($mutationId, $this->user);

    expect($this->db->connection()->table('forecast_scenario_mutations')->where('id', $mutationId)->count())->toBe(0);
    Event::assertDispatched(ScenarioMutated::class, fn (ScenarioMutated $e): bool => $e->mutationId === $mutationId && $e->kind === 'cancel_series');
});

it('9b. RemoveScenarioMutation cross-user 404 throws NotFoundHttpException', function (): void {
    /** @var CreateScenario $create */
    $create = $this->app->make(CreateScenario::class);
    /** @var AddScenarioMutation $add */
    $add = $this->app->make(AddScenarioMutation::class);
    /** @var RemoveScenarioMutation $remove */
    $remove = $this->app->make(RemoveScenarioMutation::class);

    $seriesId = scSeriesId($this->db, $this->user->id);
    $scenarioId = ($create)($this->user, 'My scenario');
    $mutationId = ($add)($scenarioId, $this->user, 'cancel_series', new CancelSeriesPayload(seriesId: $seriesId));

    expect(fn () => ($remove)($mutationId, $this->other))->toThrow(NotFoundHttpException::class);
});

it('10. EditScenarioMutation happy path: updates payload + dispatches ScenarioMutated', function (): void {
    Event::fake([ScenarioMutated::class]);
    /** @var CreateScenario $create */
    $create = $this->app->make(CreateScenario::class);
    /** @var AddScenarioMutation $add */
    $add = $this->app->make(AddScenarioMutation::class);
    /** @var EditScenarioMutation $edit */
    $edit = $this->app->make(EditScenarioMutation::class);

    $seriesA = scSeriesId($this->db, $this->user->id, 'Netflix');
    $seriesB = scSeriesId($this->db, $this->user->id, 'Spotify');
    $scenarioId = ($create)($this->user, 'Edit scenario');
    $mutationId = ($add)($scenarioId, $this->user, 'cancel_series', new CancelSeriesPayload(seriesId: $seriesA));

    ($edit)($mutationId, $this->user, new CancelSeriesPayload(seriesId: $seriesB));

    $row = $this->db->connection()->table('forecast_scenario_mutations')->where('id', $mutationId)->first();
    expect($row->target_series_id)->toBe($seriesB);
    expect(json_decode((string) $row->payload, true)['seriesId'])->toBe($seriesB);
    Event::assertDispatched(ScenarioMutated::class, fn (ScenarioMutated $e): bool => $e->mutationId === $mutationId);
});

it('11. EditScenarioMutation kind mismatch on new payload throws', function (): void {
    /** @var CreateScenario $create */
    $create = $this->app->make(CreateScenario::class);
    /** @var AddScenarioMutation $add */
    $add = $this->app->make(AddScenarioMutation::class);
    /** @var EditScenarioMutation $edit */
    $edit = $this->app->make(EditScenarioMutation::class);

    $seriesId = scSeriesId($this->db, $this->user->id);
    $scenarioId = ($create)($this->user, 'Edit-mismatch scenario');
    $mutationId = ($add)($scenarioId, $this->user, 'cancel_series', new CancelSeriesPayload(seriesId: $seriesId));

    expect(fn () => ($edit)($mutationId, $this->user, new AddOneOffPayload(
        date: '2026-06-15',
        amountMinor: 1000,
        currency: 'EUR',
        direction: 'expense',
    )))->toThrow(InvalidArgumentException::class);
});

it('12. Listener fan-out: ScenarioMutated triggers exactly 6 ProjectForecastJob dispatches (3 baseline + 3 affected)', function (): void {
    // Bus::fake() rebinds the dispatcher, and the listener takes its copy at
    // construction, so it has to be resolved after the fake, not before.
    Bus::fake();
    $this->app->make(ProjectForecastOnScenarioChange::class);
    /** @var CreateScenario $create */
    $create = $this->app->make(CreateScenario::class);
    /** @var AddScenarioMutation $add */
    $add = $this->app->make(AddScenarioMutation::class);

    $seriesId = scSeriesId($this->db, $this->user->id);
    // The second scenario is the tripwire: the listener must fan out to the
    // baseline and the mutated scenario only, never to every scenario owned.
    $unrelatedId = ($create)($this->user, 'Unrelated scenario');
    $scenarioId = ($create)($this->user, 'Affected scenario');

    // A fresh fake drops the dispatches the two CreateScenario calls caused, so
    // the count below covers the AddScenarioMutation alone.
    Bus::fake();
    $this->app->forgetInstance(ProjectForecastOnScenarioChange::class);
    $this->app->make(ProjectForecastOnScenarioChange::class);
    ($add)($scenarioId, $this->user, 'cancel_series', new CancelSeriesPayload(seriesId: $seriesId));

    Bus::assertDispatchedTimes(ProjectForecastJob::class, 10);
    Bus::assertDispatched(ProjectForecastJob::class, fn (ProjectForecastJob $j): bool => $j->scenarioId === null && $j->horizonDays === 30);
    Bus::assertDispatched(ProjectForecastJob::class, fn (ProjectForecastJob $j): bool => $j->scenarioId === $scenarioId && $j->horizonDays === 60);
    Bus::assertNotDispatched(ProjectForecastJob::class, fn (ProjectForecastJob $j): bool => $j->scenarioId === $unrelatedId);
});

it('13. ScenarioApplier cancel_series: removes matching contributions, leaves others untouched', function (): void {
    /** @var CreateScenario $create */
    $create = $this->app->make(CreateScenario::class);
    /** @var AddScenarioMutation $add */
    $add = $this->app->make(AddScenarioMutation::class);
    /** @var ScenarioApplier $applier */
    $applier = $this->app->make(ScenarioApplier::class);

    $seriesA = scSeriesId($this->db, $this->user->id, 'Netflix');
    $seriesB = scSeriesId($this->db, $this->user->id, 'Spotify');
    $scenarioId = ($create)($this->user, 'Cancel Netflix');
    ($add)($scenarioId, $this->user, 'cancel_series', new CancelSeriesPayload(seriesId: $seriesA));

    $asOf = CarbonImmutable::parse('2026-05-19');
    $baseline = [
        new ForecastContribution(date: $asOf->addDays(5), pointMinor: -1199, lowMinor: -1259, highMinor: -1139, currency: 'EUR', seriesId: $seriesA, accountId: 1),
        new ForecastContribution(date: $asOf->addDays(10), pointMinor: -999, lowMinor: -1049, highMinor: -949, currency: 'EUR', seriesId: $seriesB, accountId: 1),
    ];

    $result = $applier->apply($baseline, $scenarioId, $this->user, $asOf, 30);

    expect($result)->toHaveCount(1);
    expect($result[0]->seriesId)->toBe($seriesB);
});

it('14. ScenarioApplier add_one_off: appends a new contribution inside the horizon', function (): void {
    /** @var CreateScenario $create */
    $create = $this->app->make(CreateScenario::class);
    /** @var AddScenarioMutation $add */
    $add = $this->app->make(AddScenarioMutation::class);
    /** @var ScenarioApplier $applier */
    $applier = $this->app->make(ScenarioApplier::class);

    $scenarioId = ($create)($this->user, 'One-off');
    ($add)($scenarioId, $this->user, 'add_one_off', new AddOneOffPayload(
        date: '2026-05-25',
        amountMinor: 5000,
        currency: 'EUR',
        direction: 'expense',
    ));

    $asOf = CarbonImmutable::parse('2026-05-19');
    $baseline = [
        new ForecastContribution(date: $asOf->addDays(5), pointMinor: -1199, lowMinor: -1259, highMinor: -1139, currency: 'EUR', seriesId: 99, accountId: 1),
    ];

    $result = $applier->apply($baseline, $scenarioId, $this->user, $asOf, 30);

    expect($result)->toHaveCount(2);
    $oneOff = $result[1];
    expect($oneOff->pointMinor)->toBe(-5000);
    expect($oneOff->date->toDateString())->toBe('2026-05-25');
});

it('15. ScenarioApplier change_series_amount: rewrites low/point/high using new amount', function (): void {
    /** @var CreateScenario $create */
    $create = $this->app->make(CreateScenario::class);
    /** @var AddScenarioMutation $add */
    $add = $this->app->make(AddScenarioMutation::class);
    /** @var ScenarioApplier $applier */
    $applier = $this->app->make(ScenarioApplier::class);

    $seriesId = scSeriesId($this->db, $this->user->id, 'Spotify', -999);
    $scenarioId = ($create)($this->user, 'Spotify price hike');
    ($add)($scenarioId, $this->user, 'change_series_amount', new ChangeSeriesAmountPayload(seriesId: $seriesId, newAmountMinor: 1149));

    $asOf = CarbonImmutable::parse('2026-05-19');
    $baseline = [
        new ForecastContribution(date: $asOf->addDays(5), pointMinor: -999, lowMinor: -1049, highMinor: -949, currency: 'EUR', seriesId: $seriesId, accountId: 1),
    ];

    $result = $applier->apply($baseline, $scenarioId, $this->user, $asOf, 30);

    expect($result)->toHaveCount(1);
    // The series' 5% tolerance redraws the band: -1149 × 1.05 and × 0.95.
    expect($result[0]->pointMinor)->toBe(-1149);
    expect($result[0]->lowMinor)->toBe(-1206);
    expect($result[0]->highMinor)->toBe(-1092);
});

it('16. ScenarioApplier shift_series_date with scope=next: shifts only the first matching occurrence', function (): void {
    /** @var CreateScenario $create */
    $create = $this->app->make(CreateScenario::class);
    /** @var AddScenarioMutation $add */
    $add = $this->app->make(AddScenarioMutation::class);
    /** @var ScenarioApplier $applier */
    $applier = $this->app->make(ScenarioApplier::class);

    $seriesId = scSeriesId($this->db, $this->user->id);
    $scenarioId = ($create)($this->user, 'Shift next');
    ($add)($scenarioId, $this->user, 'shift_series_date', new ShiftSeriesDatePayload(
        seriesId: $seriesId,
        newNextDate: '2026-06-02',
        scope: 'next',
    ));

    $asOf = CarbonImmutable::parse('2026-05-19');
    $baseline = [
        new ForecastContribution(date: $asOf->addDays(6), pointMinor: -1199, lowMinor: -1259, highMinor: -1139, currency: 'EUR', seriesId: $seriesId, accountId: 1),
        new ForecastContribution(date: $asOf->addDays(36), pointMinor: -1199, lowMinor: -1259, highMinor: -1139, currency: 'EUR', seriesId: $seriesId, accountId: 1),
    ];

    $result = $applier->apply($baseline, $scenarioId, $this->user, $asOf, 90);

    $dates = array_map(static fn (ForecastContribution $c): string => $c->date->toDateString(), $result);
    sort($dates);
    expect($dates)->toBe(['2026-06-02', '2026-06-24']);
});

it('17. ScenarioApplier shift_series_date with scope=all_subsequent: shifts every matching occurrence', function (): void {
    /** @var CreateScenario $create */
    $create = $this->app->make(CreateScenario::class);
    /** @var AddScenarioMutation $add */
    $add = $this->app->make(AddScenarioMutation::class);
    /** @var ScenarioApplier $applier */
    $applier = $this->app->make(ScenarioApplier::class);

    $seriesId = scSeriesId($this->db, $this->user->id);
    $scenarioId = ($create)($this->user, 'Shift all');
    ($add)($scenarioId, $this->user, 'shift_series_date', new ShiftSeriesDatePayload(
        seriesId: $seriesId,
        newNextDate: '2026-06-02',
        scope: 'all_subsequent',
    ));

    $asOf = CarbonImmutable::parse('2026-05-19');
    $baseline = [
        new ForecastContribution(date: $asOf->addDays(6), pointMinor: -1199, lowMinor: -1259, highMinor: -1139, currency: 'EUR', seriesId: $seriesId, accountId: 1),
        new ForecastContribution(date: $asOf->addDays(36), pointMinor: -1199, lowMinor: -1259, highMinor: -1139, currency: 'EUR', seriesId: $seriesId, accountId: 1),
    ];

    $result = $applier->apply($baseline, $scenarioId, $this->user, $asOf, 90);

    $dates = array_map(static fn (ForecastContribution $c): string => $c->date->toDateString(), $result);
    sort($dates);
    expect($dates)->toBe(['2026-06-02', '2026-07-02']);
});

// CarbonImmutable::parse() answers NOW for a blank string rather than throwing,
// so a shift payload carrying no date computed a delta against today and moved
// the whole series there. The payload now refuses every string that is not a
// calendar day, which is a stronger claim: the mutation cannot be built at all.
it('17a. a shift payload refuses a date that is not a calendar day', function (string $newNextDate): void {
    $seriesId = scSeriesId($this->db, $this->user->id);

    expect(fn (): ShiftSeriesDatePayload => new ShiftSeriesDatePayload(
        seriesId: $seriesId,
        newNextDate: $newNextDate,
        scope: 'all_subsequent',
    ))->toThrow(InvalidArgumentException::class);
})->with(['   ', '2027-02-29', 'tomorrow', '2026-6-1']);

// Refused at the DTO, so nothing is stored and nothing is applied: the same
// outcome the blank-date arm above used to assert from the other end.
it('17b. ScenarioApplier leaves the baseline where a refused shift was never stored', function (): void {
    /** @var CreateScenario $create */
    $create = $this->app->make(CreateScenario::class);
    /** @var ScenarioApplier $applier */
    $applier = $this->app->make(ScenarioApplier::class);

    $seriesId = scSeriesId($this->db, $this->user->id);
    $scenarioId = ($create)($this->user, 'Shift nowhere');

    $asOf = CarbonImmutable::parse('2026-05-19');
    $baseline = [
        new ForecastContribution(date: $asOf->addDays(6), pointMinor: -1199, lowMinor: -1259, highMinor: -1139, currency: 'EUR', seriesId: $seriesId, accountId: 1),
        new ForecastContribution(date: $asOf->addDays(36), pointMinor: -1199, lowMinor: -1259, highMinor: -1139, currency: 'EUR', seriesId: $seriesId, accountId: 1),
    ];

    $result = $applier->apply($baseline, $scenarioId, $this->user, $asOf, 90);

    $dates = array_map(static fn (ForecastContribution $c): string => $c->date->toDateString(), $result);
    sort($dates);
    expect($dates)->toBe(['2026-05-25', '2026-06-24']);
});

it('19. ScenarioApplier add_one_off on an empty baseline falls back to the user lowest-id account', function (): void {
    /** @var CreateScenario $create */
    $create = $this->app->make(CreateScenario::class);
    /** @var AddScenarioMutation $add */
    $add = $this->app->make(AddScenarioMutation::class);
    /** @var ScenarioApplier $applier */
    $applier = $this->app->make(ScenarioApplier::class);

    $low = $this->db->connection()->table('accounts')->insertGetId([
        'user_id' => $this->user->id,
        'name' => 'A-low',
        'slug' => 'a-low',
        'kind' => 'bank',
        'iban' => 'SCALOW001',
        'default_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
    $this->db->connection()->table('accounts')->insertGetId([
        'user_id' => $this->user->id,
        'name' => 'B-high',
        'slug' => 'b-high',
        'kind' => 'bank',
        'iban' => 'SCAHIGH002',
        'default_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $scenarioId = ($create)($this->user, 'One-off on empty baseline');
    ($add)($scenarioId, $this->user, 'add_one_off', new AddOneOffPayload(
        date: '2026-05-25',
        amountMinor: 5000,
        currency: 'EUR',
        direction: 'expense',
    ));

    $asOf = CarbonImmutable::parse('2026-05-19');
    $result = $applier->apply([], $scenarioId, $this->user, $asOf, 30);

    expect($result)->toHaveCount(1);
    expect($result[0]->accountId)->toBe($low);
    expect($result[0]->pointMinor)->toBe(-5000);
});

it('20. ScenarioApplier pickAccountIdForOneOff: deterministic tie-break by accountId ASC', function (): void {
    /** @var CreateScenario $create */
    $create = $this->app->make(CreateScenario::class);
    /** @var AddScenarioMutation $add */
    $add = $this->app->make(AddScenarioMutation::class);
    /** @var ScenarioApplier $applier */
    $applier = $this->app->make(ScenarioApplier::class);

    $scenarioId = ($create)($this->user, 'Tie-break one-off');
    ($add)($scenarioId, $this->user, 'add_one_off', new AddOneOffPayload(
        date: '2026-05-25',
        amountMinor: 5000,
        currency: 'EUR',
        direction: 'expense',
    ));

    $asOf = CarbonImmutable::parse('2026-05-19');
    // One contribution each, higher accountId fed first: the old arsort
    // tie-broke on insertion order and picked 42, the uksort picks 7.
    $baseline = [
        new ForecastContribution(date: $asOf->addDays(5), pointMinor: -100, lowMinor: -100, highMinor: -100, currency: 'EUR', seriesId: 100, accountId: 42),
        new ForecastContribution(date: $asOf->addDays(6), pointMinor: -100, lowMinor: -100, highMinor: -100, currency: 'EUR', seriesId: 101, accountId: 7),
    ];

    $result = $applier->apply($baseline, $scenarioId, $this->user, $asOf, 30);

    expect($result)->toHaveCount(3);
    $oneOff = $result[2];
    expect($oneOff->accountId)->toBe(7);
});

it('18. ScenarioApplier source file does NOT contain JOINs onto transaction-substrate tables', function (): void {
    $contents = (string) file_get_contents(base_path('Modules/Forecasting/Internal/Pipeline/ScenarioApplier.php'));
    $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;

    foreach (['transactions', 'recurring_series_occurrences', 'chain_links', 'card_statements'] as $table) {
        expect(preg_match("/->(join|leftJoin|rightJoin|crossJoin)\\(\\s*['\"]{$table}['\"]/", $stripped) === 1)->toBeFalse();
    }
    expect(preg_match("/->table\\(['\"](transactions|recurring_series|card_statements|chain_links|drift_alerts)['\"]\\)[^;]*->(update|insert|delete|truncate)\\s*\\(/", $stripped) === 1)->toBeFalse();
});
