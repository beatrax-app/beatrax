<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Services\BaseCurrency;

function baseCurrency(mixed $configured): BaseCurrency
{
    /** @var Repository $config */
    $config = app(Repository::class);
    $config->set('currency.base', $configured);

    return new BaseCurrency($config);
}

it('returns the configured base currency code when set to a non-empty string', function (): void {
    expect(baseCurrency('USD')->code())->toBe('USD');
});

it('defaults to the Currency::Eur value when the config key is absent', function (): void {
    expect(baseCurrency(null)->code())->toBe(Currency::Eur->value);
});

it('defaults to the Currency::Eur value when the config key is an empty string', function (): void {
    expect(baseCurrency('')->code())->toBe(Currency::Eur->value);
});

it('defaults to the Currency::Eur value when the config key is a non-string', function (): void {
    expect(baseCurrency(1234)->code())->toBe(Currency::Eur->value);
});

it('ships EUR as the shipped config default', function (): void {
    /** @var Repository $config */
    $config = app(Repository::class);
    expect($config->get('currency.base'))->toBe(Currency::Eur->value);
});

it('resolves the configured code through the static view-layer accessor', function (): void {
    config(['currency.base' => 'USD']);

    expect(BaseCurrency::value())->toBe('USD');
});

it('falls the static accessor back to the Currency::Eur default when the config key is blank', function (): void {
    config(['currency.base' => '']);

    expect(BaseCurrency::value())->toBe(Currency::Eur->value);
});
