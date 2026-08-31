<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Anomaly\Internal\Detectors\DuplicateChargeDetector;
use Modules\Anomaly\Tests\Support\AnomalyCorpusSeeder;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    CarbonImmutable::setTestNow('2026-06-16 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function duplicateFires(mixed $app, DatabaseManager $db, int $txnId, mixed $user): bool
{
    /** @var DuplicateChargeDetector $detector */
    $detector = $app->make(DuplicateChargeDetector::class);

    return $detector->fires(
        AnomalyCorpusSeeder::transactionRow($db, $txnId),
        $user,
        $user->anomaly_min_amount_minor,
    );
}

it('finds an earlier-dated sibling that a newest-first import gave the higher id', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $laterId = AnomalyCorpusSeeder::seed($this->db, $user, AnomalyCorpusSeeder::load('duplicate-newest-first-import'));

    $earlierId = (int) $this->db->connection()->table('transactions')
        ->where('user_id', $user->id)
        ->where('posted_at', '2026-06-12')
        ->value('id');

    expect($earlierId)->toBeGreaterThan($laterId)
        ->and(duplicateFires($this->app, $this->db, $laterId, $user))->toBeTrue();
});

it('still fires exactly once — on the later-dated charge, whatever the ids say', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $laterId = AnomalyCorpusSeeder::seed($this->db, $user, AnomalyCorpusSeeder::load('duplicate-newest-first-import'));

    $earlierId = (int) $this->db->connection()->table('transactions')
        ->where('user_id', $user->id)
        ->where('posted_at', '2026-06-12')
        ->value('id');

    expect(duplicateFires($this->app, $this->db, $laterId, $user))->toBeTrue()
        ->and(duplicateFires($this->app, $this->db, $earlierId, $user))->toBeFalse();
});

// The `id <` tie-break still has to settle a genuine double-tap at a terminal,
// where both captures carry the same posted_at.
it('settles a same-day pair on the id so the earlier row does not fire as well', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $fixture = AnomalyCorpusSeeder::load('duplicate-in-window');
    $fixture['history'][0]['posted_at'] = '2026-06-15';
    $fixture['history'][0]['booked_at'] = '2026-06-15 10:02:00';
    $fixture['transaction']['booked_at'] = '2026-06-15 10:03:00';
    $secondId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    $firstId = (int) $this->db->connection()->table('transactions')
        ->where('user_id', $user->id)
        ->where('id', '!=', $secondId)
        ->value('id');

    expect(duplicateFires($this->app, $this->db, $secondId, $user))->toBeTrue()
        ->and(duplicateFires($this->app, $this->db, $firstId, $user))->toBeFalse();
});

it('resolves the nearest prior sibling, not whichever row the scan reached first', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, AnomalyCorpusSeeder::load('duplicate-three-siblings'));

    expect(duplicateFires($this->app, $this->db, $txnId, $user))->toBeFalse();
});
