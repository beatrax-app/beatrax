<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Exceptions\NotAuthenticatedException;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Services\BaseCurrency;

function baseCurrency(mixed $installDefault, ?User $reader, bool $console = false): BaseCurrency
{
    $currentUser = Mockery::mock(CurrentUser::class);
    if ($reader instanceof User) {
        $currentUser->shouldReceive('user')->andReturn($reader);
    } else {
        $currentUser->shouldReceive('user')->andThrow(new NotAuthenticatedException('no user'));
    }

    $app = Mockery::mock(Application::class);
    $app->shouldReceive('runningInConsole')->andReturn($console);

    return new BaseCurrency(new Repository(['currency' => ['base' => $installDefault]]), $currentUser, $app);
}

function reader(?string $chosen): User
{
    $user = new User;
    $user->base_currency = $chosen;

    return $user;
}

it('answers the currency the reader chose, not the install default', function (): void {
    expect(baseCurrency(Currency::Eur->value, reader('USD'))->code())->toBe('USD');
});

it('answers the same choice through the static accessor Blade reaches for', function (): void {
    $service = baseCurrency(Currency::Eur->value, reader('GBP'));
    app()->instance(BaseCurrency::class, $service);

    expect(BaseCurrency::value())->toBe('GBP');
});

it('answers the reader choice for a user handed to it directly', function (): void {
    expect(baseCurrency(Currency::Eur->value, null, console: true)->forUser(reader('JPY')))->toBe('JPY');
});

// A pre-migration row: the column was added nullable with no backfill, so
// every user who existed before it carries NULL and has never chosen.
it('answers the install default for a reader who has never chosen', function (): void {
    expect(baseCurrency('GBP', reader(null))->code())->toBe('GBP');
});

it('answers the install default for a user handed to it who has never chosen', function (): void {
    expect(baseCurrency('GBP', null, console: true)->forUser(reader(null)))->toBe('GBP');
});

// The fail-closed half, on UserScope's split: a web request with no reader has
// no currency to answer with, and a guessed sign over a real total is the
// damage the setting exists to prevent.
it('refuses to answer a web request that has no reader', function (): void {
    expect(fn (): string => baseCurrency(Currency::Eur->value, null)->code())
        ->toThrow(NotAuthenticatedException::class);
});

it('answers the install default in the console, which has no reader to have a preference', function (): void {
    expect(baseCurrency('USD', null, console: true)->code())->toBe('USD');
});

it('names the install default on its own, with no reader involved', function (): void {
    expect(baseCurrency('USD', reader('JPY'))->installDefault())->toBe('USD');
});

it('falls the install default back to the shipped euro when the config key is absent', function (): void {
    expect(baseCurrency(null, reader(null), console: true)->code())->toBe(Currency::Eur->value);
});

it('falls the install default back to the shipped euro when the config key is an empty string', function (): void {
    expect(baseCurrency('', reader(null), console: true)->code())->toBe(Currency::Eur->value);
});

it('falls the install default back to the shipped euro when the config key is a non-string', function (): void {
    expect(baseCurrency(1234, reader(null), console: true)->code())->toBe(Currency::Eur->value);
});

it('ships EUR as the shipped config default', function (): void {
    expect(config('currency.base'))->toBe(Currency::Eur->value);
});
