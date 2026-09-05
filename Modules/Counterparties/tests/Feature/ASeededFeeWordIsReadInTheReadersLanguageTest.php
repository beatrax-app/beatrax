<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserCountry;
use Modules\Counterparties\Public\Contracts\CounterpartyResolver;
use Modules\Counterparties\Public\Enums\CounterpartyType;
use Modules\Counterparties\Public\Queries\CounterpartyIndexQuery;
use Modules\Counterparties\Public\Support\CounterpartyDefaultName;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

// The bank-fee corpus names 257 fees in the jurisdiction's own language, and
// the resolver wrote that word into `display_name` with nothing beside it
// saying whose words they were. A reader in any of the other twenty-five
// languages read "Rente" off the counterparty list with no key to fall back
// from — the same defect the placeholder name had, one seam along.
const FEE_WORD_MIGRATION = 'Modules/Counterparties/Database/Migrations/2026_09_05_000002_mark_a_seeded_bank_fee_name_as_the_apps_own.php';

function feeWordUser(string $username): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    app(UserCountry::class)->store($user->id, 'nl');

    return $user;
}

function feeWordAccount(User $user, string $slug): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN '.$slug,
        'slug' => $slug,
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);
}

// RENTE is the Dutch fee word the corpus canonicalises to "Rente" and files
// under `debit_interest` — a kind this branch adds, so it proves the whole
// vocabulary rather than the one token that already resolved.
function feeWordCanonical(User $user, Account $account): CanonicalTransaction
{
    return new CanonicalTransaction(
        userId: $user->id,
        accountId: $account->id,
        type: 'expense',
        postedAt: CarbonImmutable::parse('2026-03-01'),
        bookedAt: CarbonImmutable::parse('2026-03-01 12:00:00'),
        valueDate: CarbonImmutable::parse('2026-03-01'),
        amountMinor: -450,
        currency: 'EUR',
        settledAmountMinor: -450,
        settledCurrency: 'EUR',
        counterpartyName: null,
        counterpartyIban: null,
        counterpartyNormalized: '',
        normalizationVersion: 1,
        description: 'RENTE 2026',
        categoryId: null,
        sourceFormat: 'camt053',
        importRunId: 1,
        sourceRowIndex: 1,
        sourceRef: 'fee-word:1',
    );
}

// Written straight through the query builder, the way every row already on
// disk was: the point of the fixture is a fee row the corpus named before
// anything recorded which kind of charge that name was for.
function legacyFeeWordRow(User $user, string $slug, string $name): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $user->id,
        'type' => CounterpartyType::Bank->value,
        'slug' => $slug,
        'display_name' => $name,
        'iban' => null,
        'merchant_name' => null,
        'metadata' => json_encode(['subcategory' => 'fee', 'matched_keyword' => 'regex:\bRENTE\b']),
        'created_at' => '2026-03-01 09:00:00',
        'updated_at' => '2026-03-01 09:00:00',
    ]);
}

function runFeeWordMigration(): void
{
    $migration = require base_path(FEE_WORD_MIGRATION);
    assert($migration instanceof Migration);
    $migration->up();
}

function feeWordOnTheIndex(User $user, string $slug): string
{
    return app(CounterpartyIndexQuery::class)->forUser($user)
        ->firstOrFail(static fn (object $row): bool => $row->slug === $slug)
        ->displayName;
}

it('records which kind of charge the corpus word names', function (): void {
    $user = feeWordUser('fee-word-marks');
    $account = feeWordAccount($user, 'fee-word-marks-asn');

    $resolved = app(CounterpartyResolver::class)->resolve(feeWordCanonical($user, $account), $user);
    expect($resolved?->type)->toBe(CounterpartyType::Bank->value);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('counterparties')->where('user_id', $user->id)->firstOrFail();

    // The column still holds the jurisdiction's word, because the slug is
    // derived from it and the reader who named this country often reads it.
    expect($row->display_name)->toBe('Rente')
        ->and($row->slug)->toBe('rente')
        ->and(CounterpartyDefaultName::tokenIn($row->metadata))->toBe('debit_interest');
});

it('reads the corpus fee word back in the language the reader is in', function (): void {
    $user = feeWordUser('fee-word-reader');
    $account = feeWordAccount($user, 'fee-word-reader-asn');
    app(CounterpartyResolver::class)->resolve(feeWordCanonical($user, $account), $user);

    app()->setLocale('en');
    expect(feeWordOnTheIndex($user, 'rente'))->toBe('Debit interest');

    app()->setLocale('de');
    expect(feeWordOnTheIndex($user, 'rente'))->toBe('Sollzinsen');

    app()->setLocale('nl');
    expect(feeWordOnTheIndex($user, 'rente'))->toBe('Debetrente');
});

it('repairs a fee row the corpus already named on an existing install', function (): void {
    $user = feeWordUser('fee-word-legacy');
    legacyFeeWordRow($user, 'rente', 'Rente');

    app()->setLocale('en');
    expect(feeWordOnTheIndex($user, 'rente'))->toBe('Rente');

    runFeeWordMigration();

    expect(feeWordOnTheIndex($user, 'rente'))->toBe('Debit interest');
});

// The slug is the only readable shadow of a sealed column, so it is what the
// backfill asks. A reader who renamed the row slugged it to something the
// corpus never wrote, and their words stay theirs in every language.
it('leaves a fee row the reader renamed in the reader\'s own words', function (): void {
    $user = feeWordUser('fee-word-renamed');
    legacyFeeWordRow($user, 'rente-van-de-hypotheek', 'Rente van de hypotheek');

    runFeeWordMigration();

    app()->setLocale('en');
    expect(feeWordOnTheIndex($user, 'rente-van-de-hypotheek'))->toBe('Rente van de hypotheek');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('counterparties')->where('user_id', $user->id)->firstOrFail();
    expect(CounterpartyDefaultName::tokenIn($row->metadata))->toBeNull();
});

it('runs twice without changing what the first pass decided', function (): void {
    $user = feeWordUser('fee-word-twice');
    legacyFeeWordRow($user, 'rente', 'Rente');

    runFeeWordMigration();
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $first = $db->connection()->table('counterparties')->where('user_id', $user->id)->firstOrFail()->metadata;

    runFeeWordMigration();
    $second = $db->connection()->table('counterparties')->where('user_id', $user->id)->firstOrFail()->metadata;

    expect($second)->toBe($first);
});
