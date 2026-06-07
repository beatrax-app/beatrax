<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Modules\FX\Database\Seeders\BundledRatesSeeder;

describe('BundledRatesSeeder', function (): void {
    it('inserts rows from the bundled snapshot into exchange_rates', function (): void {
        $db = app(DatabaseManager::class);
        $seeder = new BundledRatesSeeder($db);

        $seeder->run();

        // Snapshot has at least USD and GBP
        expect(DB::table('exchange_rates')->where([
            'base_currency' => 'EUR',
            'quote_currency' => 'USD',
            'source' => 'bundled',
        ])->exists())->toBeTrue();

        expect(DB::table('exchange_rates')->where([
            'base_currency' => 'EUR',
            'quote_currency' => 'GBP',
            'source' => 'bundled',
        ])->exists())->toBeTrue();
    });

    it('inserts rows with the snapshot date (not today)', function (): void {
        $db = app(DatabaseManager::class);
        $seeder = new BundledRatesSeeder($db);

        $seeder->run();

        $row = DB::table('exchange_rates')->where([
            'base_currency' => 'EUR',
            'quote_currency' => 'USD',
            'source' => 'bundled',
        ])->first();

        expect($row)->not->toBeNull();
        expect($row->rate_date)->toBe('2026-06-05'); // snapshot date
    });

    it('is idempotent — re-running does not duplicate rows', function (): void {
        $db = app(DatabaseManager::class);
        $seeder = new BundledRatesSeeder($db);

        $seeder->run();
        $seeder->run();

        $count = DB::table('exchange_rates')->where([
            'base_currency' => 'EUR',
            'quote_currency' => 'USD',
            'source' => 'bundled',
        ])->count();

        expect($count)->toBe(1);
    });

    it('inserts all currency pairs from the snapshot', function (): void {
        $db = app(DatabaseManager::class);
        $seeder = new BundledRatesSeeder($db);

        $seeder->run();

        // Snapshot has 30 currency pairs
        $count = DB::table('exchange_rates')->where([
            'base_currency' => 'EUR',
            'source' => 'bundled',
        ])->count();

        expect($count)->toBeGreaterThanOrEqual(30);
    });

    it('stores rates as strings that match the snapshot values', function (): void {
        $db = app(DatabaseManager::class);
        $seeder = new BundledRatesSeeder($db);

        $seeder->run();

        $row = DB::table('exchange_rates')->where([
            'base_currency' => 'EUR',
            'quote_currency' => 'USD',
            'source' => 'bundled',
        ])->first();

        expect($row)->not->toBeNull();
        // Rate from snapshot is 1.1359 — stored as decimal
        expect((float) $row->rate)->toBeGreaterThan(1.1)
            ->and((float) $row->rate)->toBeLessThan(1.2);
    });
});
