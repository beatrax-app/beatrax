<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Import\Internal\Sync\NullImportSyncCapture;
use Modules\Import\Public\Contracts\CapturesImportForSync;
use Modules\Import\Public\Dto\AdoptedBooking;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Dto\TransactionBooking;

uses(RefreshDatabase::class);

// The capture contract is bound with bindIf, so Import supplies this one and
// Sync replaces it wherever Sync is loaded. Both halves have to be answerable
// by a device with no peer at all: an import on such a device is a complete
// import, and a restatement on it is a complete restatement.

// A contract half added without an answer here is a fatal on a build that
// ships Import without Sync, which no test in the Sync suite can see.
it('answers both halves of the capture contract where there is no peer to tell', function (): void {
    $capture = new NullImportSyncCapture;

    expect($capture)->toBeInstanceOf(CapturesImportForSync::class);

    $booking = new AdoptedBooking(
        transactionId: 1,
        booking: new TransactionBooking(
            postedAt: '2026-02-19',
            bookedAt: '2026-02-19 00:00:00',
            valueDate: '2026-02-19',
            occurrenceOrdinal: 1,
        ),
    );

    $capture->capture(new ImportRun, new User);
    $capture->captureAdoptedBookings([$booking], new User);

    // Neither half may reach for a writer, a device identity or a backfill:
    // there is no Sync module on this build to hold one.
    expect(app(DatabaseManager::class)->connection()->table('op_log_entries')->count())->toBe(0);
});
