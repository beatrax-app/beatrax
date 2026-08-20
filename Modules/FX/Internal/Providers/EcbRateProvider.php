<?php

declare(strict_types=1);

namespace Modules\FX\Internal\Providers;

use Illuminate\Http\Client\Factory as HttpFactory;
use Modules\FX\Public\Contracts\RateProvider;
use Modules\FX\Public\Exceptions\RateFetchException;
use SimpleXMLElement;

final class EcbRateProvider implements RateProvider
{
    // ECB daily reference feed, published ~16:00 CET on each business
    // day (Mon-Fri, not public holidays). Priority 200 - tried first in
    // the registry chain.
    private const string URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

    private const string NS = 'http://www.ecb.int/vocabulary/2002-08-01/eurofxref';

    // Http\Factory is constructor-injected, never the Http facade, so
    // this class satisfies the arch test forbidding Illuminate facades
    // inside Modules\.
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
            // LIBXML_NONET | LIBXML_NOCDATA: the feed needs no external
            // resource to parse, so any entity reaching out over the network
            // is hostile. Matches the entity hardening Camt053Adapter applies
            // to the other XML the app ingests.
            $xml = new SimpleXMLElement($body, LIBXML_NONET | LIBXML_NOCDATA);
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
