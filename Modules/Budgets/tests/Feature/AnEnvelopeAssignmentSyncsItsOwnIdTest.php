<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\NavCountsService;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Currency;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Sync\Public\Events\EnvelopeAssignmentMutated;

// `envelope_assignments` is one of the tables a write to invalidates the
// sidebar badges, and the badge listener writes a `cache` row from inside that
// INSERT's own event. insertGetId() reads lastInsertId(), which is per
// connection, so the assignment's sync op carried the `cache` row's id.

uses(RefreshDatabase::class);

// The phones put the cache in the database, on the connection every other
// statement uses (mobile-app/bootstrap/app.php). The suite runs it in an array
// and cannot see the interleave at all, so the store goes back where the
// device keeps it — and the singletons that already hold one are dropped.
beforeEach(function (): void {
    config(['cache.default' => 'database']);
    app()->forgetInstance('cache.store');
    app()->forgetInstance(CacheRepository::class);
    app()->forgetInstance(NavCountsService::class);
    app('cache')->forgetDriver(['array', 'database']);

    Currency::query()->updateOrInsert(['code' => 'EUR'], ['name' => 'Euro', 'minor_unit' => 2]);

    $this->groceries = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'own-id-groceries-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    $this->owner = User::create([
        'username' => 'own-id-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'EUR',
    ]);
});

it('gives the sync op the id the assignment row actually has', function (): void {
    $this->actingAs($this->owner);

    // The badges are read when the page loads, which is what puts the first
    // row in `cache` and moves the generation key's rowid off the id the
    // assignment is about to be given.
    app(NavCountsService::class)->forUser($this->owner->id);

    $announced = [];
    app(Dispatcher::class)->listen(
        EnvelopeAssignmentMutated::class,
        function (EnvelopeAssignmentMutated $event) use (&$announced): void {
            $announced[] = $event->assignmentId;
        },
    );

    $period = app(PeriodQuery::class)->current();
    app(EnvelopeWriter::class)->setAssigned($this->owner, $this->groceries->id, $period->start, 50000);

    $rowId = DB::table('envelope_assignments')->where('user_id', $this->owner->id)->value('id');

    expect(DB::table('cache')->count())->toBeGreaterThan(1)
        ->and($announced)->toHaveCount(1)
        ->and($announced[0])->toBe((int) $rowId);
});
