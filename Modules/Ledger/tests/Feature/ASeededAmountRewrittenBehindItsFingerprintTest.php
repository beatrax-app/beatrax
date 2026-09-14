<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Ledger\Public\Services\FingerprintHealthCheck;

uses(RefreshDatabase::class);

// The fingerprint is composed over eight columns of the row it keys, and a
// builder update writes one of them without re-deriving it. IcsSettlementAligner
// rewrote amount_minor on both legs of every settlement it aligned, so a tree
// that had only ever been seeded opened with four digests describing nothing.
it('leaves no seeded transaction carrying a digest of values it no longer holds', function (): void {
    $this->artisan('demo:seed')->assertSuccessful();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $rows = $db->connection()->table('transactions');

    $total = $rows->count();
    $atVersion = $rows->clone()->where('fingerprint_version', app(FingerprintComposer::class)->version())->count();

    // The check reads only rows at the current version, so both numbers are
    // asserted: a seed that wrote older ones would leave it nothing to look at
    // and this would pass while saying nothing.
    expect($total)->toBeGreaterThan(0);
    expect($atVersion)->toBe($total);

    $check = app(FingerprintHealthCheck::class);

    expect($check->severity())->toBe('ok', sprintf(
        'The demo seed left a fingerprint that stopped describing its row: %s',
        $check->message(),
    ));
});
