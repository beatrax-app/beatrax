<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\Services\FingerprintComposer;

it('produces a stable 64-hex SHA-256 for identical inputs', function (): void {
    $composer = new FingerprintComposer;

    $a = $composer->compose($this->canonical());
    $b = $composer->compose($this->canonical());

    expect($a)->toHaveLength(64);
    expect($a)->toMatch('/^[0-9a-f]{64}$/');
    expect($a)->toBe($b);
})->group('phase-2');

it('exposes NORMALIZATION_VERSION as 3', function (): void {
    expect((new FingerprintComposer)->version())->toBe(3);
    expect(FingerprintComposer::NORMALIZATION_VERSION)->toBe(3);
})->group('phase-2');

it('produces an identical hash when only sourceRef differs', function (): void {
    $composer = new FingerprintComposer;

    $a = $composer->compose($this->canonical(['sourceRef' => null]));
    $b = $composer->compose($this->canonical(['sourceRef' => 'ENDTOEND-XYZ']));
    $c = $composer->compose($this->canonical(['sourceRef' => '']));

    expect($a)->toBe($b);
    expect($a)->toBe($c);
})->group('phase-2');

it('produces a different hash when bookedAt differs by one second', function (): void {
    $composer = new FingerprintComposer;

    $a = $composer->compose($this->canonical(['bookedAt' => CarbonImmutable::parse('2026-04-12 09:14:33')]));
    $b = $composer->compose($this->canonical(['bookedAt' => CarbonImmutable::parse('2026-04-12 09:14:34')]));

    expect($a)->not->toBe($b);
})->group('phase-2');

it('is sensitive to every other v3 tuple field', function (string $field, mixed $alternate): void {
    $composer = new FingerprintComposer;

    $base = $composer->compose($this->canonical());
    $variant = $composer->compose($this->canonical([$field => $alternate]));

    expect($variant)->not->toBe($base);
})->with([
    'userId' => ['userId', 99],
    'accountId' => ['accountId', 99],
    'postedAt' => ['postedAt', CarbonImmutable::parse('2026-06-01')],
    'amountMinor' => ['amountMinor', -200],
    'currency' => ['currency', 'USD'],
    'counterpartyNormalized' => ['counterpartyNormalized', 'different-merchant'],
])->group('phase-2');

it('treats a null userId as zero in the v3 tuple', function (): void {
    $composer = new FingerprintComposer;

    $withNull = $composer->compose($this->canonical(['userId' => null]));
    $withZero = $composer->compose($this->canonical(['userId' => 0]));

    expect($withNull)->toBe($withZero);
})->group('phase-2');

it('normalises a counterparty name (lowercase + diacritics + punctuation)', function (): void {
    $composer = new FingerprintComposer;

    expect($composer->normalize('AH AMSTERDAM-NL.   '))->toBe('ah amsterdam nl');
})->group('phase-2');

it('strips diacritics from a counterparty name', function (): void {
    $composer = new FingerprintComposer;

    expect($composer->normalize('Café Plein'))->toBe('cafe plein');
})->group('phase-2');

it('returns empty string for an empty input to normalize', function (): void {
    $composer = new FingerprintComposer;

    expect($composer->normalize(''))->toBe('');
})->group('phase-2');

it('truncates normalised names beyond 80 characters', function (): void {
    $composer = new FingerprintComposer;
    $long = str_repeat('a', 200);

    expect(strlen($composer->normalize($long)))->toBe(80);
})->group('phase-2');
