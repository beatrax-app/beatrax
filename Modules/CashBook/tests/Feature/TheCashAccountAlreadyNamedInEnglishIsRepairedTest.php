<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;

// "Cash" is already on disk: a phone read it as account 7 on an install that
// had been adding entries for weeks. A fix that only changes future writes
// leaves every such install reading English, so the row already written is
// repaired under the same predicate the writer now uses.
const CASH_ACCOUNT_RELABEL_MIGRATION = 'Modules/CashBook/Database/Migrations/2026_08_30_000003_relabel_the_cash_account_the_app_named_in_english.php';

function englishCashMigration(): object
{
    return require base_path(CASH_ACCOUNT_RELABEL_MIGRATION);
}

function englishCashUser(string $username, ?string $locale): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    DB::table('users')->where('id', $user->id)->update(['locale' => $locale]);

    return $user;
}

// The row exactly as RecordManualTransaction used to write it, straight through
// the query builder: the point of the fixture is a row the new writer never saw.
function englishCashAccount(User $user, string $name = 'Cash'): int
{
    return (int) DB::table('accounts')->insertGetId([
        'user_id' => $user->id,
        'name' => $name,
        'slug' => 'cash',
        'kind' => 'cash',
        'iban' => 'CASH'.str_pad((string) $user->id, 12, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

function englishCashName(int $accountId): mixed
{
    return DB::table('accounts')->where('id', $accountId)->value('name');
}

it('repairs the English name on the account it minted for a reader who named a language', function (): void {
    $user = englishCashUser('english-cash-dutch', 'nl');
    $accountId = englishCashAccount($user);

    englishCashMigration()->up();

    expect(englishCashName($accountId))->toBe('Contant');
});

it('leaves the row alone for a reader who named no language, because the app has no answer on disk', function (): void {
    $user = englishCashUser('english-cash-system', null);
    $accountId = englishCashAccount($user);

    englishCashMigration()->up();

    expect(englishCashName($accountId))->toBe('Cash');
});

it('leaves an account the reader themselves named "Cash" exactly as they wrote it', function (): void {
    $user = englishCashUser('english-cash-user-named', 'nl');

    $ownId = (int) DB::table('accounts')->insertGetId([
        'user_id' => $user->id,
        'name' => 'Cash',
        'slug' => 'cash-2',
        'kind' => 'bank',
        'iban' => 'NL22ASNB0555999111',
        'default_currency' => 'EUR',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    englishCashMigration()->up();

    expect(englishCashName($ownId))->toBe('Cash');
});

it('leaves a cash account carrying somebody else\'s name alone', function (): void {
    $user = englishCashUser('english-cash-renamed', 'nl');
    $accountId = englishCashAccount($user, 'Kleingeld');

    englishCashMigration()->up();

    expect(englishCashName($accountId))->toBe('Kleingeld');
});

it('writes nothing on a second run', function (): void {
    $user = englishCashUser('english-cash-rerun', 'nl');
    $accountId = englishCashAccount($user);

    englishCashMigration()->up();
    $stamp = DB::table('accounts')->where('id', $accountId)->value('updated_at');

    englishCashMigration()->up();

    expect(englishCashName($accountId))->toBe('Contant')
        ->and(DB::table('accounts')->where('id', $accountId)->value('updated_at'))->toBe($stamp);
});
