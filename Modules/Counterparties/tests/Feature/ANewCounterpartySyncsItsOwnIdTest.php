<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\NavCountsService;
use Modules\Counterparties\Public\Contracts\CounterpartyResolver;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Sync\Public\Events\EntityMutated;

// `counterparties` is one of the tables a write to invalidates the sidebar
// badges, and the badge listener writes a `cache` row from inside that INSERT's
// own event. firstOrCreate() ends in insertGetId(), which reads lastInsertId() —
// per connection — so the new row's create op carried the `cache` row's id.

uses(RefreshDatabase::class);

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

    $this->owner = User::query()->create([
        'username' => 'cp-own-id-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    $this->row = new CanonicalTransaction(
        userId: $this->owner->id,
        accountId: 1,
        type: 'expense',
        postedAt: CarbonImmutable::parse('2026-03-01'),
        bookedAt: CarbonImmutable::parse('2026-03-01 09:00:00'),
        valueDate: CarbonImmutable::parse('2026-03-01'),
        amountMinor: -1299,
        currency: 'EUR',
        settledAmountMinor: -1299,
        settledCurrency: 'EUR',
        counterpartyName: 'Mystery Stall',
        counterpartyIban: null,
        counterpartyNormalized: 'mystery-stall',
        normalizationVersion: 1,
        description: null,
        categoryId: null,
        sourceFormat: 'asn-csv',
        importRunId: 1,
        sourceRowIndex: 0,
        sourceRef: 'cp-own-id-1',
    );
});

it('gives the create op the id the counterparty row actually has', function (): void {
    // The badges are read when the page loads, which is what puts the first row
    // in `cache` and moves the generation key's rowid off the id the
    // counterparty is about to be given.
    app(NavCountsService::class)->forUser($this->owner->id);

    $announced = [];
    app(Dispatcher::class)->listen(
        EntityMutated::class,
        function (EntityMutated $event) use (&$announced): void {
            if ($event->table === 'counterparties') {
                $announced[] = $event->pk;
            }
        },
    );

    $resolved = app(CounterpartyResolver::class)->resolve($this->row, $this->owner);

    $rowId = DB::table('counterparties')->where('user_id', $this->owner->id)->value('id');

    expect(DB::table('cache')->count())->toBeGreaterThan(1)
        ->and($rowId)->not->toBeNull()
        ->and($resolved?->counterpartyId)->toBe((int) $rowId)
        ->and($announced)->toBe([(int) $rowId]);
});
