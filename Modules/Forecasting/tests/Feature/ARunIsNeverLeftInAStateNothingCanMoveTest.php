<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Forecasting\Internal\Pipeline\ProjectionPipeline;
use Modules\Forecasting\Internal\StateMachines\ForecastRunStateMachine;

uses(RefreshDatabase::class);

// fail() is the only writer of `failed`, and the pipeline's catch is the only
// caller of fail(). The opening transition used to sit above that try, so a
// throw from it left a row `pending` with no reachable writer -- and
// ForecastQuery reads pending as "still computing", which is a chart that says
// it is updating forever.

it('marks the run failed when the opening transition itself throws', function (): void {
    $user = User::query()->create([
        'username' => 'rnl-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // Throws on the first read taken once a pending row exists, which is the
    // one inside start(), and answers normally afterwards so the catch's own
    // fail() can still stamp the row.
    app()->instance(Clock::class, new class($db) implements Clock
    {
        private bool $thrown = false;

        public function __construct(private DatabaseManager $db) {}

        public function now(): CarbonImmutable
        {
            $pending = $this->db->connection()->table('forecast_runs')->where('status', 'pending')->exists();

            if ($pending && ! $this->thrown) {
                $this->thrown = true;

                throw new RuntimeException('Synthetic transition failure');
            }

            return CarbonImmutable::parse('2026-09-05 09:00:00');
        }
    });

    // The state machine is a container singleton and holds its own Clock, so
    // it has to be rebuilt against the double or start() keeps the real one.
    app()->forgetInstance(ForecastRunStateMachine::class);

    expect(fn () => app(ProjectionPipeline::class)->project($user, null, 30))
        ->toThrow(RuntimeException::class);

    $statuses = $db->connection()->table('forecast_runs')
        ->where('user_id', $user->id)->pluck('status')->all();

    expect($statuses)->toBe(['failed']);
});
