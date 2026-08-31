<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\DB;
use Modules\Core\Public\Services\NavCountsService;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Receipts\Internal\ReceiptLedgerBridge;
use Modules\Receipts\Public\Dto\ParsedReceiptDto;

// `import_runs` is one of the tables a write to invalidates the sidebar badges,
// and the badge listener writes a `cache` row from inside that INSERT's own
// event. firstOrCreate() ends in insertGetId(), which reads lastInsertId() —
// per connection — so the bridged receipt named a run that does not exist.

// The phones put the cache in the database, on the connection every other
// statement uses (mobile-app/bootstrap/app.php), and so does a self-hosted
// server. The suite runs it in an array and cannot see the interleave at all,
// so the store goes back where the device keeps it.
beforeEach(function (): void {
    config(['cache.default' => 'database']);
    app()->forgetInstance('cache.store');
    app()->forgetInstance(CacheRepository::class);
    app()->forgetInstance(NavCountsService::class);
    app('cache')->forgetDriver(['array', 'database']);

    $this->freezeClockOnTheStatementFixtureWindow();
    $this->seedFixtureUserAndAccount();

    $this->receipt = new ParsedReceiptDto(
        merchantName: 'Handoff Merchant',
        amountMinor: -1299,
        currency: 'EUR',
        settledAmountMinor: null,
        settledCurrency: null,
        referenceId: 'handoff-own-id-1',
        bookedAt: CarbonImmutable::parse('2026-05-15 10:00:00'),
        ownIban: 'PAYPAL',
        description: 'Handoff receipt',
        rawPayload: [],
    );
});

it('files a bridged receipt under the handoff run the bridge opened', function (): void {
    // The badges are read when the page loads, which is what puts the first row
    // in `cache` and moves the generation key's rowid off the id the handoff run
    // is about to be given.
    app(NavCountsService::class)->forUser($this->fixtureUser->id);

    $bridged = app(ReceiptLedgerBridge::class)->bridge(
        $this->receipt,
        $this->fixtureUser,
        null,
        SourceFormat::Eml,
    );

    $rowId = DB::table('import_runs')
        ->where('user_id', $this->fixtureUser->id)
        ->where('source_format', 'inbox-handoff')
        ->value('id');

    $filedUnder = DB::table('transactions')
        ->where('user_id', $this->fixtureUser->id)
        ->distinct()
        ->pluck('import_run_id')
        ->all();

    expect(DB::table('cache')->count())->toBeGreaterThan(1)
        ->and($bridged)->toBe((int) $rowId)
        ->and($filedUnder)->toBe([(int) $rowId]);
});
