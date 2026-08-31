<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\CashBook\Internal\Actions\RecordManualTransaction;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\Direction;

// The first cash entry on a phone minted an account the app named "Cash", in
// English, whatever the reader was reading in — and, being data rather than a
// key, it never re-resolved. The words below are the ones the app already
// ships for this money on every row's payment-type chip.

function readersWordUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function readersWordEntry(User $user, string $locale): void
{
    app()->setLocale($locale);

    app(RecordManualTransaction::class)(
        $user,
        Direction::Expense->value,
        1234,
        CarbonImmutable::create(2026, 5, 1, 12, 0, 0),
        'Bakery',
    );
}

function readersWordCashAccount(User $user): stdClass
{
    $row = DB::table('accounts')->where('user_id', $user->id)->where('kind', 'cash')->first();

    return $row instanceof stdClass ? $row : new stdClass;
}

it('names the account it mints in the language the reader is reading', function (): void {
    $user = readersWordUser('readers-word-dutch');

    readersWordEntry($user, 'nl');

    $account = readersWordCashAccount($user);

    expect($account->name)->toBe('Contant')
        ->and($account->name)->not->toBe('Cash');
});

it('keeps the slug out of the reader language so the unique index never churns', function (): void {
    $user = readersWordUser('readers-word-slug');

    readersWordEntry($user, 'nl');

    expect(readersWordCashAccount($user)->slug)->toBe('cash');
});

it('re-resolves the name it wrote when the reader changes language', function (): void {
    $user = readersWordUser('readers-word-switch');

    readersWordEntry($user, 'nl');
    expect(readersWordCashAccount($user)->name)->toBe('Contant');

    readersWordEntry($user, 'de');
    expect(readersWordCashAccount($user)->name)->toBe('Bar');
});

it('leaves an account the reader themselves named "Cash" exactly as they wrote it', function (): void {
    $user = readersWordUser('readers-word-own-name');

    DB::table('accounts')->insert([
        'user_id' => $user->id,
        'name' => 'Cash',
        'slug' => 'cash',
        'kind' => 'bank',
        'iban' => 'NL22ASNB0555999111',
        'default_currency' => 'EUR',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    readersWordEntry($user, 'nl');

    expect(DB::table('accounts')->where('user_id', $user->id)->where('kind', 'bank')->value('name'))
        ->toBe('Cash');
});

it('leaves a cash account it did not mint alone', function (): void {
    $user = readersWordUser('readers-word-demo-cash');

    DB::table('accounts')->insert([
        'user_id' => $user->id,
        'name' => 'Japan Trip Cash',
        'slug' => 'jpy-cash-demo-1',
        'kind' => 'cash',
        'iban' => 'CASH-DEMO-1-JPY',
        'default_currency' => 'JPY',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    readersWordEntry($user, 'nl');

    expect(DB::table('accounts')->where('user_id', $user->id)->where('iban', 'CASH-DEMO-1-JPY')->value('name'))
        ->toBe('Japan Trip Cash');
});
