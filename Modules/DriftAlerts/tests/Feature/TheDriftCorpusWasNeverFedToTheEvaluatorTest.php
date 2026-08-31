<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Internal\DriftEvaluator;
use Modules\DriftAlerts\Tests\Support\DriftCorpusSeeder;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    CarbonImmutable::setTestNow('2026-05-19 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @return iterable<string, array{0: string}>
 */
function driftCorpusNames(): iterable
{
    /** @var list<string> $paths */
    $paths = glob(__DIR__.'/../fixtures/drift-corpus/*.php') ?: [];
    sort($paths);
    foreach ($paths as $path) {
        $name = basename($path, '.php');
        yield $name => [$name];
    }
}

// Every fixture used to be checked against its own other literals, so the
// evaluator could return anything at all and the corpus still passed.
it('replays each corpus fixture through the real evaluator and gets the rows the fixture predicts', function (string $name): void {
    /** @var array<string, mixed> $fixture */
    $fixture = require __DIR__.'/../fixtures/drift-corpus/'.$name.'.php';

    $user = User::query()->create([
        'username' => 'corpus-'.bin2hex(random_bytes(5)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    /** @var DriftEvaluator $evaluator */
    $evaluator = $this->app->make(DriftEvaluator::class);
    $seriesId = DriftCorpusSeeder::replay($this->db, $user, $fixture, $evaluator);

    /** @var array{alerts: list<array<string, mixed>>} $expected */
    $expected = $fixture['expected'];

    $actual = $this->db->connection()->table('drift_alerts')
        ->where('user_id', $user->id)
        ->where('recurring_series_id', $seriesId)
        ->orderBy('id')
        ->get()
        ->all();

    expect($actual)->toHaveCount(count($expected['alerts']));

    foreach ($expected['alerts'] as $index => $expectedAlert) {
        /** @var stdClass $row */
        $row = $actual[$index];
        foreach ($expectedAlert as $column => $value) {
            expect($row->{$column})->toBe($value, "{$name}: alert #{$index}.{$column}");
        }
    }
})->with(driftCorpusNames());
