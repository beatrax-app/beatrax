<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Budgets\Public\Services\EnvelopePeriodRekeyer;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Internal\Listeners\ForgetNavCountsOnWrite;
use Modules\Core\Internal\Support\MigrationWindow;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\NavCountsService;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Currency;
use Modules\Sync\Public\Events\EnvelopeAssignmentMutated;

// The rekey deletes every assignment and re-files it, and each new row's id
// rides an EnvelopeAssignmentMutated create op to every paired device. A rowid
// belonging to `cache` would replay against a stranger's row on the peer.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 09:00:00'));
    Currency::query()->updateOrInsert(['code' => 'EUR'], ['name' => 'Euro', 'minor_unit' => 2]);

    $this->user = User::create([
        'username' => 'rekey-own-id-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 15,
        'base_currency' => 'EUR',
    ]);
    $this->actingAs($this->user);

    $category = fn (string $name): Category => Category::create([
        'user_id' => null,
        'name' => $name,
        'slug' => 'rekey-own-id-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);
    $this->groceries = $category('Groceries');
    $this->transport = $category('Transport');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// The badge listener over a DatabaseStore on the connection under test, which
// is where the phones and a self-hosted server keep the cache. Built here rather
// than switched on in config, so the row it writes lands on the one statement
// whose id is read back rather than on the delete that happens to precede it.
function rekeyOwnIdWatchTheAssignmentInsert(): void
{
    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $store = new Repository(new DatabaseStore($manager->connection(), 'cache'));

    // Three warm entries, so the rowid the generation key is about to take
    // cannot coincide with the id an assignment is given.
    foreach (range(1, 3) as $n) {
        $store->put('rekey-own-id-warm-'.$n, $n, 600);
    }

    $listener = new ForgetNavCountsOnWrite(
        new NavCountsService($manager, $store, app(Clock::class)),
        new MigrationWindow,
    );

    DB::listen(static function (QueryExecuted $query) use ($listener): void {
        if (str_starts_with(ltrim($query->sql), 'insert into "envelope_assignments"')) {
            $listener->handle($query);
        }
    });
}

it('gives every rekeyed create op the id its row actually has', function (): void {
    DB::table('users')->where('id', $this->user->id)->update(['envelope_activated_at' => '2026-06-10 09:00:00']);
    $this->user->refresh();

    $writer = app(EnvelopeWriter::class);
    $writer->setAssigned($this->user, $this->groceries->id, CarbonImmutable::parse('2026-07-15'), 10000);
    $writer->setAssigned($this->user, $this->transport->id, CarbonImmutable::parse('2026-08-15'), 20000);

    rekeyOwnIdWatchTheAssignmentInsert();

    $announced = [];
    app(Dispatcher::class)->listen(
        EnvelopeAssignmentMutated::class,
        function (EnvelopeAssignmentMutated $event) use (&$announced): void {
            if ($event->mutationType === 'create') {
                $announced[] = $event->assignmentId;
            }
        },
    );

    DB::table('users')->where('id', $this->user->id)->update(['period_start_day' => 28]);
    $this->user->refresh();
    app(EnvelopePeriodRekeyer::class)->rekeyToCurrentPeriods(15);

    $rowIds = DB::table('envelope_assignments')->where('user_id', $this->user->id)->pluck('id')->all();

    sort($rowIds);
    sort($announced);

    expect(DB::table('cache')->count())->toBeGreaterThan(3)
        ->and($announced)->not->toBeEmpty()
        ->and($announced)->toBe($rowIds);
});
