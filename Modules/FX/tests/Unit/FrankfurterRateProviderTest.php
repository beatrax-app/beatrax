<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory as HttpFactory;
use Modules\FX\Internal\Providers\FrankfurterRateProvider;
use Modules\FX\Public\Exceptions\RateFetchException;

describe('FrankfurterRateProvider', function (): void {
    it('parses a valid Frankfurter JSON response into the contract shape', function (): void {
        $json = [
            'amount' => 1,
            'base' => 'EUR',
            'date' => '2026-06-05',
            'rates' => [
                'USD' => 1.1359,
                'GBP' => 0.83895,
                'AUD' => 1.7284,
            ],
        ];

        Http::fake([
            'https://api.frankfurter.dev/v1/latest' => Http::response($json, 200),
        ]);

        $provider = new FrankfurterRateProvider(app(HttpFactory::class));

        $result = $provider->fetch();

        expect($result)->toBeArray()
            ->and($result['date'])->toBe('2026-06-05')
            ->and($result['rates'])->toBeArray()
            ->and($result['rates']['USD'])->toBe('1.1359')
            ->and($result['rates']['GBP'])->toBe('0.83895')
            ->and($result['rates']['AUD'])->toBe('1.7284');
    });

    it('casts all rates to strings, never floats', function (): void {
        $json = [
            'amount' => 1,
            'base' => 'EUR',
            'date' => '2026-06-05',
            'rates' => [
                'USD' => 1.1359,
                'GBP' => 0.83895,
            ],
        ];

        Http::fake([
            'https://api.frankfurter.dev/v1/latest' => Http::response($json, 200),
        ]);

        $provider = new FrankfurterRateProvider(app(HttpFactory::class));
        $result = $provider->fetch();

        foreach ($result['rates'] as $rate) {
            expect($rate)->toBeString();
        }
    });

    it('uses the correct Frankfurter URL (api.frankfurter.dev not api.frankfurter.app)', function (): void {
        $json = [
            'amount' => 1,
            'base' => 'EUR',
            'date' => '2026-06-05',
            'rates' => ['USD' => 1.1359],
        ];

        Http::fake([
            'https://api.frankfurter.dev/v1/latest' => Http::response($json, 200),
            '*' => Http::response('unexpected', 400),
        ]);

        $provider = new FrankfurterRateProvider(app(HttpFactory::class));
        $result = $provider->fetch();

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'api.frankfurter.dev');
        });

        expect($result['date'])->toBe('2026-06-05');
    });

    it('throws RateFetchException on HTTP failure', function (): void {
        Http::fake([
            'https://api.frankfurter.dev/v1/latest' => Http::response('', 500),
        ]);

        $provider = new FrankfurterRateProvider(app(HttpFactory::class));

        expect(fn () => $provider->fetch())->toThrow(RateFetchException::class);
    });

    it('throws RateFetchException when the JSON response is missing the date field', function (): void {
        Http::fake([
            'https://api.frankfurter.dev/v1/latest' => Http::response([
                'amount' => 1,
                'base' => 'EUR',
                'rates' => ['USD' => 1.1359],
                // no 'date' key
            ], 200),
        ]);

        $provider = new FrankfurterRateProvider(app(HttpFactory::class));

        expect(fn () => $provider->fetch())->toThrow(RateFetchException::class);
    });

    it('has key "frankfurter" and priority 100', function (): void {
        $provider = new FrankfurterRateProvider(app(HttpFactory::class));

        expect($provider->key())->toBe('frankfurter')
            ->and($provider->priority())->toBe(100);
    });
});
