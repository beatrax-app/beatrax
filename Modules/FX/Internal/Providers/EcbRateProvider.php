<?php

declare(strict_types=1);

namespace Modules\FX\Internal\Providers;

use Illuminate\Http\Client\Factory as HttpFactory;
use Modules\FX\Public\Contracts\RateProvider;
use Modules\FX\Public\Exceptions\RateFetchException;
use SimpleXMLElement;

/**
 * Fetches EUR-based exchange rates from the ECB daily reference XML feed.
 *
 * URL: https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml
 * Published: ~16:00 CET on each ECB business day (Mon–Fri, not public holidays).
 * Key: 'ecb', Priority: 200 (highest — tried first in the registry chain).
 *
 * Rate values are cast to string; never float (Pitfall 1 / T-02-03).
 *
 * The Http\Factory is constructor-injected (never the Http facade) to satisfy
 * BoundaryArchTest's "no Illuminate\Support\Facades inside Modules\" rule
 * (Pitfall 5 / T-02-04).
 */
final class EcbRateProvider implements RateProvider
{
    private const string URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

    private const string NS = 'http://www.ecb.int/vocabulary/2002-08-01/eurofxref';

    public function __construct(private readonly HttpFactory $http) {}

    public function key(): string
    {
        return 'ecb';
    }

    public function priority(): int
    {
        return 200;
    }

    /**
     * @return array{date: string, rates: array<string, string>}
     *
     * @throws RateFetchException
     */
    public function fetch(): array
    {
        try {
            $body = $this->http
                ->createPendingRequest()
                ->get(self::URL)
                ->throw()
                ->body();
        } catch (\Throwable $e) {
            throw new RateFetchException('ECB HTTP request failed: '.$e->getMessage(), 0, $e);
        }

        return $this->parse($body);
    }

    /**
     * @return array{date: string, rates: array<string, string>}
     *
     * @throws RateFetchException
     */
    private function parse(string $body): array
    {
        try {
            $xml = new SimpleXMLElement($body);
        } catch (\Throwable $e) {
            throw new RateFetchException('ECB XML parse failed: '.$e->getMessage(), 0, $e);
        }

        $xml->registerXPathNamespace('ecb', self::NS);

        /** @var array<SimpleXMLElement>|false $cubes */
        $cubes = $xml->xpath('//ecb:Cube[@time]');

        if ($cubes === false || $cubes === []) {
            throw new RateFetchException('ECB XML: no Cube[@time] element found.');
        }

        $dateCube = $cubes[0];
        $dateAttr = $dateCube['time'];

        if ($dateAttr === null) {
            throw new RateFetchException('ECB XML: Cube element has no time attribute.');
        }

        $date = (string) $dateAttr;

        /** @var array<string, string> $rates */
        $rates = [];

        foreach ($dateCube->Cube as $cube) {
            $currency = (string) $cube['currency'];
            $rate = (string) $cube['rate'];

            if ($currency === '' || $rate === '') {
                continue;
            }

            $rates[$currency] = $rate;
        }

        if ($rates === []) {
            throw new RateFetchException('ECB XML: no currency rates found in the Cube element.');
        }

        return ['date' => $date, 'rates' => $rates];
    }
}
