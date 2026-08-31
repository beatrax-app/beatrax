<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\NavCountsService;
use Modules\Ledger\Database\Seeders\Demo\DemoTransactionsSeeder;
use Modules\Ledger\Database\Seeders\Demo\DemoTransferPairsSeeder;
use Modules\Ledger\Models\Account;

// `import_runs` is one of the tables a write to invalidates the sidebar badges,
// and the badge listener writes a `cache` row from inside that INSERT's own
// event. updateOrCreate() ends in insertGetId(), which reads lastInsertId() —
// per connection — so the demo legs named a run that does not exist.

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

    $this->demoUser = User::query()->create([
        'username' => 'demo-1',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    $account = function (string $slug, string $kind, string $iban): Account {
        return Account::query()->create([
            'user_id' => $this->demoUser->id,
            'name' => 'Demo '.$kind,
            'slug' => $slug,
            'kind' => $kind,
            'iban' => $iban,
            'default_currency' => 'EUR',
        ]);
    };

    $this->demoAccounts = [
        'asn-demo-1' => $account('asn-demo-1', 'bank', 'NL57ASNB0000000042'),
        'paypal-demo-1' => $account('paypal-demo-1', 'paypal', 'PAYPAL'),
        'ics-demo-1' => $account('ics-demo-1', 'ics_card', 'ICS-CARD'),
        'jpy-demo-1' => $account('jpy-demo-1', 'bank', 'JP1234567890123456'),
    ];

    $this->filedUnderRunsThatExist = function (): array {
        $runIds = DB::table('import_runs')->where('user_id', $this->demoUser->id)->pluck('id')->all();
        $filedUnder = DB::table('transactions')
            ->where('user_id', $this->demoUser->id)
            ->distinct()
            ->pluck('import_run_id')
            ->all();
        sort($runIds);
        sort($filedUnder);

        return [$runIds, $filedUnder];
    };
});

it('files every demo transfer leg under the import run the seeder opened', function (): void {
    // The badges are read when the page loads, which is what puts the first row
    // in `cache` and moves the generation key's rowid off the id the import run
    // is about to be given.
    app(NavCountsService::class)->forUser($this->demoUser->id);

    $seeded = app(DemoTransferPairsSeeder::class)->run(
        ['demo-1' => $this->demoUser],
        ['demo-1' => $this->demoAccounts],
    );

    [$runIds, $filedUnder] = ($this->filedUnderRunsThatExist)();

    expect(DB::table('cache')->count())->toBeGreaterThan(1)
        ->and($seeded)->toBe(2)
        ->and($filedUnder)->toBe($runIds);
});

it('files every demo statement row under the import run the seeder opened', function (): void {
    app(NavCountsService::class)->forUser($this->demoUser->id);

    $seeded = app(DemoTransactionsSeeder::class)->run(
        ['demo-1' => $this->demoUser],
        ['demo-1' => $this->demoAccounts],
    );

    [$runIds, $filedUnder] = ($this->filedUnderRunsThatExist)();

    expect(DB::table('cache')->count())->toBeGreaterThan(1)
        ->and($seeded)->toBeGreaterThan(0)
        ->and($filedUnder)->toBe($runIds);
});
