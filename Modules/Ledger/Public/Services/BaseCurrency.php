<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Modules\Ledger\Public\Enums\Currency;

// Thin DI seam over config('currency.base') — the one place the app-wide
// fallback currency resolves, so callers reach for it by intent instead of
// pinning a bare 'EUR'. Falls back to the enum default if the key is absent
// or malformed.
final readonly class BaseCurrency
{
    public function __construct(private Repository $config) {}

    public function code(): string
    {
        $value = $this->config->get('currency.base');

        return is_string($value) && $value !== '' ? $value : Currency::Eur->value;
    }

    // View-layer accessor: a Blade template cannot inject BaseCurrency, so it
    // reaches the same source of truth through this static seam rather than
    // pinning 'EUR' or re-deriving the fallback. Domain code injects the
    // service and calls code() directly instead of routing through here.
    public static function value(): string
    {
        return Container::getInstance()->make(self::class)->code();
    }
}
