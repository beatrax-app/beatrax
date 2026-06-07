<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory as HttpFactory;
use Modules\FX\Internal\Providers\EcbRateProvider;
use Modules\FX\Public\Exceptions\RateFetchException;

describe('EcbRateProvider', function (): void {
    it('parses a valid ECB XML response into the contract shape', function (): void {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01"
                             xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
              <gesmes:subject>Reference rates</gesmes:subject>
              <gesmes:Sender>
                <gesmes:name>European Central Bank</gesmes:name>
              </gesmes:Sender>
              <Cube>
                <Cube time="2026-06-05">
                  <Cube currency="USD" rate="1.1359"/>
                  <Cube currency="GBP" rate="0.83895"/>
                  <Cube currency="JPY" rate="159.10"/>
                </Cube>
              </Cube>
            </gesmes:Envelope>
            XML;

        $http = Http::fake([
            'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml' => Http::response($xml, 200),
        ]);

        $provider = new EcbRateProvider(app(HttpFactory::class));

        $result = $provider->fetch();

        expect($result)->toBeArray()
            ->and($result['date'])->toBe('2026-06-05')
            ->and($result['rates'])->toBeArray()
            ->and($result['rates']['USD'])->toBe('1.1359')
            ->and($result['rates']['GBP'])->toBe('0.83895')
            ->and($result['rates']['JPY'])->toBe('159.10');
    });

    it('returns all rates as strings, never floats', function (): void {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01"
                             xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
              <Cube>
                <Cube time="2026-06-05">
                  <Cube currency="USD" rate="1.1359"/>
                  <Cube currency="GBP" rate="0.83895"/>
                </Cube>
              </Cube>
            </gesmes:Envelope>
            XML;

        Http::fake([
            'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml' => Http::response($xml, 200),
        ]);

        $provider = new EcbRateProvider(app(HttpFactory::class));
        $result = $provider->fetch();

        foreach ($result['rates'] as $rate) {
            expect($rate)->toBeString();
        }
    });

    it('throws RateFetchException on HTTP failure', function (): void {
        Http::fake([
            'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml' => Http::response('', 500),
        ]);

        $provider = new EcbRateProvider(app(HttpFactory::class));

        expect(fn () => $provider->fetch())->toThrow(RateFetchException::class);
    });

    it('throws RateFetchException on malformed XML (no Cube time attribute)', function (): void {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01"
                             xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
              <Cube>
                <Cube>
                  <Cube currency="USD" rate="1.1359"/>
                </Cube>
              </Cube>
            </gesmes:Envelope>
            XML;

        Http::fake([
            'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml' => Http::response($xml, 200),
        ]);

        $provider = new EcbRateProvider(app(HttpFactory::class));

        expect(fn () => $provider->fetch())->toThrow(RateFetchException::class);
    });

    it('has key "ecb" and priority 200', function (): void {
        $provider = new EcbRateProvider(app(HttpFactory::class));

        expect($provider->key())->toBe('ecb')
            ->and($provider->priority())->toBe(200);
    });
});
