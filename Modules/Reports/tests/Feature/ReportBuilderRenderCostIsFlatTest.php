<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Reports\Internal\Http\Livewire\ReportBuilder;

uses(RefreshDatabase::class);

// The builder redraws its three filter pickers and its aggregate on every
// control the reader touches. What it must not do is redraw them once per row:
// the same render over a ledger ten times the size costs the same statements.

$rbcUser = static function (string $username): User {
    /** @var User */
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
};

$rbcLedger = static function (ConnectionInterface $conn, User $user, int $counterparties, int $perCounterparty): void {
    /** @var Account $account */
    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'rbc-asn-'.$user->id,
        'kind' => 'bank',
        'iban' => 'NL00RBC'.str_pad((string) $user->id, 8, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
    ]);

    $runId = $conn->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/rbc-'.$user->id.'.csv',
        'sha256' => hash('sha256', 'rbc-'.$user->id),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    for ($i = 0; $i < $counterparties; $i++) {
        $conn->table('categories')->insert([
            'user_id' => $user->id,
            'name' => 'Category '.$i,
            'slug' => 'rbc-cat-'.$user->id.'-'.$i,
            'kind' => 'expense',
            'name_is_default' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $counterpartyId = $conn->table('counterparties')->insertGetId([
            'user_id' => $user->id,
            'type' => 'merchant',
            'slug' => 'rbc-cp-'.$user->id.'-'.$i,
            'display_name' => 'Merchant '.$i,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $batch = [];
        for ($n = 0; $n < $perCounterparty; $n++) {
            $day = ($i * $perCounterparty + $n) % 25;
            $batch[] = [
                'user_id' => $user->id,
                'account_id' => $account->id,
                'import_run_id' => $runId,
                'counterparty_id' => $counterpartyId,
                'fingerprint' => hash('sha256', 'rbc-'.$user->id.'-'.$i.'-'.$n),
                'posted_at' => now()->startOfMonth()->addDays($day)->toDateString(),
                'booked_at' => now()->startOfMonth()->addDays($day)->toDateTimeString(),
                'value_date' => now()->startOfMonth()->toDateString(),
                'amount_minor' => -100 * ($n + 1),
                'currency' => 'EUR',
                'settled_amount_minor' => -100 * ($n + 1),
                'settled_currency' => 'EUR',
                'counterparty_normalized' => 'rbc-m'.$i,
                'counterparty_name' => 'Merchant '.$i,
                'normalization_version' => 1,
                'description' => 'rbc row '.$i.'-'.$n,
                'type' => 'expense',
                'source_format' => 'asn-csv',
                'source_row_index' => $n,
                'fingerprint_version' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        $conn->table('transactions')->insert($batch);
    }
};

// One render, counted: mount is excluded because it also runs the component's
// URL-parameter hydration, which a control change does not.
$rbcStatementsForOneRender = static function (User $user): int {
    $component = Livewire::test(ReportBuilder::class)
        ->set('dimension', 'counterparty')
        ->set('compare', true);

    $statements = 0;
    DB::listen(static function (QueryExecuted $query) use (&$statements): void {
        $statements++;
    });

    $component->set('viz', 'bar');

    return $statements;
};

it('costs the same statements on a ledger ten times the size', function () use ($rbcUser, $rbcLedger, $rbcStatementsForOneRender): void {
    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $conn = $manager->connection();

    $small = $rbcUser('rbc-small');
    $rbcLedger($conn, $small, 6, 2);
    $this->actingAs($small);
    $smallCost = $rbcStatementsForOneRender($small);

    $large = $rbcUser('rbc-large');
    $rbcLedger($conn, $large, 60, 20);
    $this->actingAs($large);
    $largeCost = $rbcStatementsForOneRender($large);

    expect($smallCost)->toBe(9)
        ->and($largeCost)->toBe($smallCost);
});

it('offers every counterparty, category and account the reader owns as a filter', function () use ($rbcUser, $rbcLedger): void {
    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $conn = $manager->connection();

    $user = $rbcUser('rbc-options');
    $rbcLedger($conn, $user, 4, 1);
    $this->actingAs($user);

    $view = Livewire::test(ReportBuilder::class)->viewData('availableCounterparties');
    $categories = Livewire::test(ReportBuilder::class)->viewData('availableCategories');
    $accounts = Livewire::test(ReportBuilder::class)->viewData('availableAccounts');

    expect(array_column($view, 'name'))->toBe(['Merchant 0', 'Merchant 1', 'Merchant 2', 'Merchant 3'])
        ->and(array_column($categories, 'name'))->toContain('Category 0', 'Category 3')
        ->and(array_column($accounts, 'name'))->toBe(['ASN']);
});
