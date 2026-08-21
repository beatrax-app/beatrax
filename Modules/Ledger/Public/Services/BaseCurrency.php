<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Modules\Ledger\Public\Enums\Currency;

// The one place the app-wide fallback currency resolves, so callers reach for
// it by intent rather than pinning a bare 'EUR'.
final readonly class BaseCurrency
{
    public function __construct(private Repository $config) {}

    public function code(): string
    {
        $value = $this->config->get('currency.base');

        return is_string($value) && $value !== '' ? $value : Currency::Eur->value;
    }

    // For Blade, which cannot inject the service. Domain code injects it and
    // calls code() directly.
    public static function value(): string
    {
        return Container::getInstance()->make(self::class)->code();
    }
}
