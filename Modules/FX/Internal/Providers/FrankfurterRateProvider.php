<?php

declare(strict_types=1);

namespace Modules\FX\Internal\Providers;

use Illuminate\Http\Client\Factory as HttpFactory;
use Modules\FX\Public\Contracts\RateProvider;
use Modules\FX\Public\Exceptions\RateFetchException;

final readonly class FrankfurterRateProvider implements RateProvider
{
    // The old api.frankfurter.app 301-redirects here; named directly to skip
    // the hop.
    private const string URL = 'https://api.frankfurter.dev/v1/latest';

    public function __construct(private HttpFactory $http) {}

    public function key(): string
    {
        return 'frankfurter';
    }

    public function priority(): int
    {
        return 100;
    }

    /**
     * @return array{date: string, rates: array<string, string>}
     *
     * @throws RateFetchException
     */
    public function fetch(): array
    {
        try {
            $json = $this->http
                ->createPendingRequest()
                ->get(self::URL)
                ->throw()
                ->json();
        } catch (\Throwable $e) {
            throw new RateFetchException('Frankfurter HTTP request failed: '.$e->getMessage(), 0, $e);
        }

        if (! is_array($json)) {
            throw new RateFetchException('Frankfurter response is not a JSON object.');
        }

        $date = $json['date'] ?? null;

        if (! is_string($date)) {
            throw new RateFetchException('Frankfurter JSON: missing or non-string "date" field.');
        }

        $rawRates = $json['rates'] ?? [];

        if (! is_array($rawRates)) {
            throw new RateFetchException('Frankfurter JSON: "rates" field is not an array.');
        }

        /** @var array<string, string> $rates */
        $rates = [];

        foreach ($rawRates as $currency => $rate) {
            if (! is_scalar($rate)) {
                continue;
            }

            $rates[(string) $currency] = (string) $rate;
        }

        // A 200 with no usable rates must fail, or the registry resets the
        // circuit and stops here instead of falling through to the snapshot.
        if ($rates === []) {
            throw new RateFetchException('Frankfurter response contained no usable rates.');
        }

        return ['date' => $date, 'rates' => $rates];
    }
}
