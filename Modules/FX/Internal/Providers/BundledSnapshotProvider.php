<?php

declare(strict_types=1);

namespace Modules\FX\Internal\Providers;

use Modules\FX\Public\Contracts\RateProvider;
use Modules\FX\Public\Exceptions\RateFetchException;

/**
 * Reads EUR-based exchange rates from the bundled offline snapshot file.
 *
 * This provider is always available as the final fallback in the registry
 * chain (Priority 0). It never makes network requests — the snapshot JSON
 * ships with the application and is seeded into `exchange_rates` on install
 * by `BundledRatesSeeder`.
 *
 * Key: 'bundled', Priority: 0 (lowest — used only when ECB and Frankfurter
 * both fail or are circuit-broken).
 *
 * The snapshot lives at `Modules/FX/Resources/rates-snapshot.json` and
 * contains EUR-based rates for all major currency pairs (USD, GBP, etc.).
 */
final class BundledSnapshotProvider implements RateProvider
{
    private string $snapshotPath;

    public function __construct(?string $snapshotPath = null)
    {
        $this->snapshotPath = $snapshotPath
            ?? __DIR__.'/../../Resources/rates-snapshot.json';
    }

    public function key(): string
    {
        return 'bundled';
    }

    public function priority(): int
    {
        return 0;
    }

    /**
     * @return array{date: string, rates: array<string, string>}
     *
     * @throws RateFetchException
     */
    public function fetch(): array
    {
        $raw = @file_get_contents($this->snapshotPath);

        if ($raw === false) {
            throw new RateFetchException('Bundled snapshot not found at: '.$this->snapshotPath);
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RateFetchException('Bundled snapshot JSON is not an object.');
        }

        $date = $decoded['date'] ?? null;

        if (! is_string($date)) {
            throw new RateFetchException('Bundled snapshot JSON: missing or non-string "date" field.');
        }

        $rawRates = $decoded['rates'] ?? null;

        if (! is_array($rawRates)) {
            throw new RateFetchException('Bundled snapshot JSON: missing or invalid "rates" field.');
        }

        /** @var array<string, string> $rates */
        $rates = [];

        foreach ($rawRates as $currency => $rate) {
            $rates[(string) $currency] = (string) $rate;
        }

        return ['date' => $date, 'rates' => $rates];
    }
}
