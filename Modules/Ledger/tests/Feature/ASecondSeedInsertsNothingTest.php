<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// demo:seed claims a re-seed is a no-op. It was, but only because the
// settlement legs carried a digest of the amount the seeder writes rather than
// the one the aligner rewrote: the second pass recomposed the stale value and
// collided with it. Correcting the digest put 4 duplicate rows in and threw.
it('changes nothing on a second run', function (): void {
    $this->artisan('demo:seed')->assertSuccessful();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // Whole rows rather than a count: a duplicate set that replaces rather than
    // adds keeps the count identical while every id underneath it has moved.
    $snapshot = static fn (): array => $db->connection()->table('transactions')
        ->orderBy('id')
        ->get(['id', 'source_ref', 'amount_minor', 'currency', 'fingerprint'])
        ->map(static fn (object $row): string => implode('|', [
            $row->id, $row->source_ref, $row->amount_minor, $row->currency, $row->fingerprint,
        ]))
        ->all();

    $before = $snapshot();

    expect($before)->not->toBeEmpty();

    $this->artisan('demo:seed')->assertSuccessful();

    expect($snapshot())->toBe($before);
});
