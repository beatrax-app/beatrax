<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\Services\FingerprintComposer;

it('produces a stable 64-hex SHA-256 for identical inputs', function (): void {
    $composer = new FingerprintComposer;
    $tx = $this->canonical();

    $a = $composer->compose($tx);
    $b = $composer->compose($this->canonical());

    expect($a)->toHaveLength(64);
    expect($a)->toMatch('/^[0-9a-f]{64}$/');
    expect($a)->toBe($b);
});

it('changes the fingerprint when any tuple element changes', function (string $field, mixed $newValue): void {
    $composer = new FingerprintComposer;
    $base = $composer->compose($this->canonical());

    $altered = $composer->compose($this->canonical([$field => $newValue]));

    expect($altered)->not->toBe($base);
})->with([
    'accountId' => ['accountId', 999],
    'postedAt' => ['postedAt', CarbonImmutable::parse('2026-06-03')],
    'amountMinor' => ['amountMinor', -1300],
    'currency' => ['currency', 'USD'],
    'counterpartyNormalized' => ['counterpartyNormalized', 'jumbo amsterdam'],
    'sourceRef' => ['sourceRef', 'ASN-REF-999'],
]);

it('treats a null source_ref as empty string (Pitfall 5)', function (): void {
    $composer = new FingerprintComposer;

    $withNull = $composer->compose($this->canonical(['sourceRef' => null]));
    $withEmpty = $composer->compose($this->canonical(['sourceRef' => '']));

    expect($withNull)->toBe($withEmpty);
});

it('returns NORMALIZATION_VERSION = 1', function (): void {
    expect((new FingerprintComposer)->version())->toBe(1);
});

it('normalises a counterparty name (lowercase + diacritics + punctuation)', function (): void {
    $composer = new FingerprintComposer;

    expect($composer->normalize('AH AMSTERDAM-NL.   '))->toBe('ah amsterdam nl');
});

it('strips diacritics from a counterparty name', function (): void {
    $composer = new FingerprintComposer;

    expect($composer->normalize('Café Plein'))->toBe('cafe plein');
});

it('returns empty string for an empty input', function (): void {
    $composer = new FingerprintComposer;

    expect($composer->normalize(''))->toBe('');
});

it('truncates names beyond 80 characters', function (): void {
    $composer = new FingerprintComposer;
    $long = str_repeat('a', 200);

    expect(strlen($composer->normalize($long)))->toBe(80);
});
