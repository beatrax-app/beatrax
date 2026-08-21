<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Import\Internal\Exceptions\InvalidAccountNameException;
use Modules\Import\Public\Services\AccountNamer;
use Modules\Ledger\Models\Account;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'namer',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
});

it('creates an account scoped to the user with the supplied name + IBAN', function (): void {
    $namer = app(AccountNamer::class);
    $iban = 'NL42TEST1234567890';

    $accountId = $namer($iban, 'My ASN Savings', $this->user);

    expect($accountId)->toBeGreaterThan(0);

    /** @var Account $account */
    $account = Account::query()->find($accountId);
    expect($account)->not->toBeNull();
    expect($account->iban)->toBe($iban);
    expect($account->user_id)->toBe($this->user->id);
    expect($account->name)->toBe('My ASN Savings');
    expect($account->kind)->toBe('bank');
    expect($account->default_currency)->toBe('EUR');
});

it('trims whitespace from the user-supplied name', function (): void {
    $namer = app(AccountNamer::class);

    $accountId = $namer('NL42TEST1234567890', '  Trimmed Account  ', $this->user);

    /** @var Account $account */
    $account = Account::query()->find($accountId);
    expect($account->name)->toBe('Trimmed Account');
});

it('derives the slug from the account name alone, never from the IBAN', function (): void {
    $namer = app(AccountNamer::class);

    $accountId = $namer('NL47TEST9876543210', 'Another Account', $this->user);

    /** @var Account $account */
    $account = Account::query()->find($accountId);
    expect($account->slug)->toBe('another-account');
    expect($account->slug)->not->toContain('3210');
    expect($account->slug)->not->toContain('9876543210');
});

it('separates two accounts sharing a name with a numeric suffix, not the IBAN', function (): void {
    $namer = app(AccountNamer::class);

    $first = $namer('NL47TEST9876543210', 'Joint Account', $this->user);
    $second = $namer('NL42TEST1234567890', 'Joint Account', $this->user);

    /** @var Account $one */
    $one = Account::query()->find($first);
    /** @var Account $two */
    $two = Account::query()->find($second);

    expect($one->slug)->toBe('joint-account');
    expect($two->slug)->toBe('joint-account-2');
    expect($two->slug)->not->toContain('7890');
});

it('rejects names that contain no alphanumeric characters (emoji only)', function (): void {
    $namer = app(AccountNamer::class);

    expect(fn () => $namer('NL49TEST1111111111', '🎉🎉', $this->user))
        ->toThrow(InvalidAccountNameException::class);
});

it('rejects names that contain only punctuation', function (): void {
    $namer = app(AccountNamer::class);

    expect(fn () => $namer('NL02TEST2222222222', '====', $this->user))
        ->toThrow(InvalidAccountNameException::class);
});

it('rejects names below the minimum length bound', function (): void {
    $namer = app(AccountNamer::class);

    expect(fn () => $namer('NL52TEST3333333333', '   ', $this->user))
        ->toThrow(InvalidAccountNameException::class);
});

it('rejects names above the maximum length bound', function (): void {
    $namer = app(AccountNamer::class);
    $tooLong = str_repeat('a', AccountNamer::NAME_MAX_LENGTH + 1);

    expect(fn () => $namer('NL05TEST4444444444', $tooLong, $this->user))
        ->toThrow(InvalidAccountNameException::class);
});

it('rejects an empty IBAN', function (): void {
    $namer = app(AccountNamer::class);

    expect(fn () => $namer('', 'Friendly Name', $this->user))
        ->toThrow(InvalidAccountNameException::class);
});

it('rejects an IBAN shorter than 15 characters', function (): void {
    $namer = app(AccountNamer::class);

    expect(fn () => $namer('NL01ABC', 'Friendly Name', $this->user))
        ->toThrow(InvalidAccountNameException::class);
});

it('rejects an IBAN longer than 34 characters', function (): void {
    $namer = app(AccountNamer::class);
    $tooLong = 'NL'.str_repeat('1', 33);

    expect(fn () => $namer($tooLong, 'Friendly Name', $this->user))
        ->toThrow(InvalidAccountNameException::class);
});

it('rejects an IBAN containing lowercase letters', function (): void {
    $namer = app(AccountNamer::class);

    expect(fn () => $namer('nl01test1234567890', 'Friendly Name', $this->user))
        ->toThrow(InvalidAccountNameException::class);
});

it('rejects an IBAN containing whitespace or punctuation', function (): void {
    $namer = app(AccountNamer::class);

    expect(fn () => $namer('NL01 TEST 1234 5678 90', 'Friendly Name', $this->user))
        ->toThrow(InvalidAccountNameException::class);
});
