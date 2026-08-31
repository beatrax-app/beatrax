<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Internal\Aggregation\CounterpartySpendQuery;

uses(RefreshDatabase::class);

// A hand-entered cash row read "No counterparty" in English on a phone set to
// Dutch. The category dimension routes both of its stand-in labels through
// Lang; the counterparty dimension carried them as literals, and the two facts
// it states are as distinct as the two CategorySpendQuery keeps apart.

// The English line, so a Dutch reading can be held against it. A missing Dutch
// line falls back to English and renders exactly what the literal did, so
// asserting the Dutch label equals its own key proves nothing on its own.
function cgnEnglish(string $key): string
{
    $reader = app()->getLocale();
    app()->setLocale('en');
    $line = Lang::get($key);
    app()->setLocale($reader);

    return $line;
}

function cgnUser(string $prefix): User
{
    /** @var User */
    return User::query()->create([
        'username' => $prefix.'-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function cgnAccount(User $user): Account
{
    /** @var Account */
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'cgn-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL00CGN'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => 'EUR',
    ]);
}

// Owned by somebody else, which is one of the three ways a counterparty_id
// reaches identitiesForIds() and comes back unresolved -- the others being a
// deleted row and a row still travelling behind its transaction over Sync.
function cgnForeignCounterparty(DatabaseManager $db, User $owner): int
{
    return $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $owner->id,
        'type' => 'merchant',
        'slug' => 'cgn-'.bin2hex(random_bytes(3)),
        'display_name' => 'CGN Vendor',
        'merchant_name' => 'CGN VENDOR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function cgnSpend(DatabaseManager $db, User $user, Account $account, ?int $counterpartyId, int $minor = -5_000): void
{
    $suffix = bin2hex(random_bytes(8));

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/cgn-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'cgn-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $db->connection()->table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => $runId,
        'type' => 'expense',
        'posted_at' => '2026-03-15',
        'booked_at' => '2026-03-15 10:00:00',
        'value_date' => '2026-03-15',
        'amount_minor' => $minor,
        'currency' => 'EUR',
        'settled_amount_minor' => $minor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'CGN Vendor',
        'counterparty_normalized' => 'cgn-vendor',
        'normalization_version' => 1,
        'category_id' => null,
        'counterparty_id' => $counterpartyId,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'cgn-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function cgnPeriod(): Period
{
    return new Period(
        start: CarbonImmutable::parse('2026-03-01'),
        endExclusive: CarbonImmutable::parse('2026-04-01'),
        label: 'March 2026',
    );
}

it('names the no-counterparty bucket in the language the report is read in', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = cgnUser('cgn');
    cgnSpend($db, $user, cgnAccount($user), null);

    $english = cgnEnglish('reports::builder.no_counterparty');
    app()->setLocale('nl');

    $rows = app(CounterpartySpendQuery::class)->forUserAndPeriod($user, cgnPeriod(), 'spend', 'EUR');

    expect(Lang::get('reports::builder.no_counterparty'))->not->toBe('reports::builder.no_counterparty');
    expect($rows)->toHaveCount(1);
    expect($rows[0]->groupKey)->toBeNull();
    expect($rows[0]->groupLabel)->toBe(Lang::get('reports::builder.no_counterparty'))
        ->and($rows[0]->groupLabel)->not->toBe($english);
});

it('names a counterparty this device cannot resolve in the language the report is read in', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = cgnUser('cgn');
    $foreignId = cgnForeignCounterparty($db, cgnUser('cgn-peer'));
    cgnSpend($db, $user, cgnAccount($user), $foreignId);

    $english = cgnEnglish('reports::builder.unavailable_counterparty');
    app()->setLocale('nl');

    $rows = app(CounterpartySpendQuery::class)->forUserAndPeriod($user, cgnPeriod(), 'spend', 'EUR');

    expect(Lang::get('reports::builder.unavailable_counterparty'))->not->toBe('reports::builder.unavailable_counterparty');
    expect($rows)->toHaveCount(1);
    expect($rows[0]->groupKey)->toBe($foreignId);
    expect($rows[0]->groupLabel)->toBe(Lang::get('reports::builder.unavailable_counterparty'))
        ->and($rows[0]->groupLabel)->not->toBe($english);
});

it('keeps the empty bucket and the unresolved counterparty two different lines', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = cgnUser('cgn');
    $account = cgnAccount($user);
    $foreignId = cgnForeignCounterparty($db, cgnUser('cgn-peer'));

    cgnSpend($db, $user, $account, null, -5_000);
    cgnSpend($db, $user, $account, $foreignId, -3_000);

    $englishLines = [
        cgnEnglish('reports::builder.no_counterparty'),
        cgnEnglish('reports::builder.unavailable_counterparty'),
    ];
    app()->setLocale('nl');

    $rows = app(CounterpartySpendQuery::class)->forUserAndPeriod($user, cgnPeriod(), 'spend', 'EUR');

    $labels = array_map(static fn (object $row): string => $row->groupLabel, $rows);

    expect($rows)->toHaveCount(2);
    expect(array_unique($labels))->toHaveCount(2);
    expect(array_intersect($labels, $englishLines))->toBe([]);
    expect($labels)->toContain(Lang::get('reports::builder.no_counterparty'))
        ->toContain(Lang::get('reports::builder.unavailable_counterparty'));
});
