<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Import\Internal\Exceptions\InvalidAccountNameException;
use Modules\Import\Public\Services\AccountNamer;
use Modules\Ledger\Models\Account;

// A preview lists the IBANs it did not recognise when the file was parsed. Two
// statements from one new bank therefore both prompt, and naming the second
// after the first has been confirmed used to hit
// accounts.user_id+iban -- an unhandled UniqueConstraintViolationException that
// reached the reader as Livewire's error modal, leaving that import previewed
// for good with no action on the screen that could move it.
it('adopts the account an IBAN already has instead of inserting a second', function (): void {
    $user = User::query()->create([
        'username' => 'namer-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
    ]);

    $namer = app(AccountNamer::class);
    $iban = 'NL57ASNB0999000111';

    $first = $namer($iban, 'Payload Test Account', $user);
    $second = $namer($iban, 'A Different Name Entirely', $user);

    expect($second)->toBe($first);

    expect(Account::query()->where('user_id', $user->id)->where('iban', $iban)->count())->toBe(1);

    // The first naming stands: the reader named this IBAN once, and a second
    // preview built before that is not a rename.
    $account = Account::query()->findOrFail($first);
    expect($account->name)->toBe('Payload Test Account');
});

it('still refuses an IBAN that is not shaped like one', function (): void {
    $user = User::query()->create([
        'username' => 'namer-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
    ]);

    expect(fn (): int => app(AccountNamer::class)('nope!', 'Some Name', $user))
        ->toThrow(InvalidAccountNameException::class);
});
