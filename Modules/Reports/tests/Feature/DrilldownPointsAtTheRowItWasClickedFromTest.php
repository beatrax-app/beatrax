<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Reports\Internal\Http\Livewire\ReportBuilder;

// build() was handed the report's whole Period for every row, so three monthly
// rows linked to one identical full-range list, and no type or direction filter
// was emitted at all: a `spend` row pointed at a list carrying salary income,
// which cannot be reconciled against the figure it was clicked from.

function dpaUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'dpa-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function dpaMovement(User $user, string $type, int $amountMinor, string $postedAt): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $suffix = bin2hex(random_bytes(8));

    /** @var Account $account */
    $account = Account::query()->firstOrCreate(
        ['user_id' => $user->id, 'slug' => 'dpa-'.$user->id],
        ['name' => 'dpa account', 'kind' => 'bank', 'iban' => 'NL00DPA'.str_pad((string) $user->id, 11, '0', STR_PAD_LEFT), 'default_currency' => 'EUR'],
    );

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/dpa-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'dpa-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $db->connection()->table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => $runId,
        'type' => $type,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 10:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'DPA Vendor',
        'counterparty_normalized' => 'dpa-vendor',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'dpa-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @return list<array<string, mixed>>
 */
function dpaUrlParams(string $metric, string $dimension): array
{
    $urls = Livewire::test(ReportBuilder::class)
        ->set('metric', $metric)
        ->set('dimension', $dimension)
        ->set('periodPreset', 'custom')
        ->set('customFrom', '2026-01-01')
        ->set('customTo', '2026-03-31')
        ->viewData('drilldownUrls');

    $parsed = [];
    foreach (is_array($urls) ? $urls : [] as $url) {
        $query = parse_url(is_string($url) ? $url : '', PHP_URL_QUERY);
        $params = [];
        parse_str(is_string($query) ? $query : '', $params);
        $parsed[] = $params;
    }

    return $parsed;
}

it('links each monthly bucket to its own month rather than the whole range', function (): void {
    $user = dpaUser();
    test()->actingAs($user);
    dpaMovement($user, 'expense', -10_000, '2026-01-15');
    dpaMovement($user, 'expense', -20_000, '2026-02-15');
    dpaMovement($user, 'expense', -30_000, '2026-03-15');

    $params = dpaUrlParams('spend', 'time_bucket');

    expect($params)->toHaveCount(3)
        ->and(array_column($params, 'after'))->toBe(['2026-01-01', '2026-02-01', '2026-03-01'])
        ->and(array_column($params, 'before'))->toBe(['2026-01-31', '2026-02-28', '2026-03-31']);
});

it('links a net-worth point to the bucket it was sampled at the end of', function (): void {
    $user = dpaUser();
    test()->actingAs($user);
    dpaMovement($user, 'income', 10_000, '2026-01-15');

    $params = dpaUrlParams('net_worth', 'category');

    expect(array_column($params, 'after'))->toBe(['2026-01-01', '2026-02-01', '2026-03-01'])
        ->and(array_column($params, 'before'))->toBe(['2026-01-31', '2026-02-28', '2026-03-31']);
});

it('narrows a spend row to money going out, so salary income cannot land in the list', function (): void {
    $user = dpaUser();
    test()->actingAs($user);
    dpaMovement($user, 'expense', -10_000, '2026-01-15');
    dpaMovement($user, 'income', 500_000, '2026-01-20');

    expect(array_column(dpaUrlParams('spend', 'category'), 'amount_dir'))->toBe(['out'])
        ->and(array_column(dpaUrlParams('income', 'category'), 'amount_dir'))->toBe(['in']);
});

it('leaves a net row undirected, since it counts both halves', function (): void {
    $user = dpaUser();
    test()->actingAs($user);
    dpaMovement($user, 'expense', -10_000, '2026-01-15');

    expect(dpaUrlParams('net', 'category')[0])->not->toHaveKey('amount_dir');
});

// A category row's window is still the report's, since a category is not a
// bucket and has no window of its own.
it('keeps the whole range on a dimension whose rows carry no window', function (): void {
    $user = dpaUser();
    test()->actingAs($user);
    dpaMovement($user, 'expense', -10_000, '2026-01-15');
    dpaMovement($user, 'expense', -20_000, '2026-03-15');

    $params = dpaUrlParams('spend', 'category');

    expect($params)->toHaveCount(1)
        ->and($params[0]['after'])->toBe('2026-01-01')
        ->and($params[0]['before'])->toBe('2026-03-31');
});
