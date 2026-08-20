<?php

declare(strict_types=1);

namespace Modules\FX\Internal\Providers;

use JsonException;
use Modules\FX\Public\Contracts\RateProvider;
use Modules\FX\Public\Exceptions\RateFetchException;

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

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            // JsonException extends Exception, not RateFetchException — without
            // this catch a corrupt snapshot would escape the whole provider
            // fallback chain, since the registry only catches
            // RateFetchException, bypassing the safe-fallback contract.
            throw new RateFetchException('Bundled snapshot JSON is malformed: '.$e->getMessage(), previous: $e);
        }

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
            if (! is_scalar($rate)) {
                continue;
            }

            $rates[(string) $currency] = (string) $rate;
        }

        // An empty rate set is a failure, not a success — otherwise the
        // registry would treat the (final) bundled provider as having
        // succeeded and write zero rows (mirrors EcbRateProvider).
        if ($rates === []) {
            throw new RateFetchException('Bundled snapshot contains no usable rates.');
        }

        return ['date' => $date, 'rates' => $rates];
    }
}
