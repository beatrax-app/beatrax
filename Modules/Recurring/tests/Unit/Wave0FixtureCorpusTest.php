<?php

declare(strict_types=1);

it('exposes exactly 11 synthesised fixture files', function (): void {
    $files = glob(__DIR__.'/../fixtures/synthesised/*.php');

    expect($files)->toBeArray();
    /** @var list<string> $files */
    expect(count($files))->toBe(11);
});

it('returns the expected top-level shape for each synthesised fixture', function (): void {
    $files = glob(__DIR__.'/../fixtures/synthesised/*.php');
    /** @var list<string> $files */
    foreach ($files as $path) {
        $basename = basename($path);
        $payload = require $path;

        expect($payload)->toBeArray();
        expect($payload)->toHaveKeys(['transactions', 'expected'], "Fixture {$basename} missing top-level keys");
        expect($payload['transactions'])->toBeArray();
        expect($payload['expected'])->toBeArray();
    }
});

it('each transaction row carries the canonical detector key set', function (): void {
    $requiredKeys = [
        'posted_at',
        'booked_at',
        'amount_minor',
        'currency',
        'counterparty_normalized',
        'type',
    ];
    $files = glob(__DIR__.'/../fixtures/synthesised/*.php');
    /** @var list<string> $files */
    foreach ($files as $path) {
        $basename = basename($path);
        $payload = require $path;
        /** @var array{transactions: list<array<string, mixed>>} $payload */
        foreach ($payload['transactions'] as $index => $row) {
            foreach ($requiredKeys as $key) {
                expect(array_key_exists($key, $row))->toBeTrue(
                    "Fixture {$basename} row {$index} missing required key '{$key}'",
                );
            }
        }
    }
});

it('exposes the real-export stub at the documented path', function (): void {
    $path = __DIR__.'/../fixtures/real/anonymised-asn-ics-6mo.php';
    expect(file_exists($path))->toBeTrue();

    $payload = require $path;
    expect($payload)->toBeArray();
    expect($payload)->toHaveKeys(['transactions', 'expected']);
});
