<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\Mt940CounterpartyCleaner;

beforeEach(function (): void {
    $this->cleaner = $this->app->make(Mt940CounterpartyCleaner::class);
});

it('strips a 3-digit GVC prefix', function (): void {
    expect($this->cleaner->clean('005 SPOTIFY AB'))->toBe('SPOTIFY AB');
})->group('phase-2');

it('strips a 4-char transaction-type-code prefix', function (): void {
    expect($this->cleaner->clean('NDDT SPOTIFY AB'))->toBe('SPOTIFY AB');
})->group('phase-2');

it('strips a BIC code embedded mid-string', function (): void {
    expect($this->cleaner->clean('STARBUCKS ABNANL2A AMSTERDAM'))->toBe('STARBUCKS AMSTERDAM');
})->group('phase-2');

it('strips /REMI/ /NAME/ /IBAN/ /BIC/ markers', function (): void {
    $cleaned = $this->cleaner->clean('/NAME/SPOTIFY AB/REMI/Subscription');

    expect($cleaned)->toContain('SPOTIFY AB');
    expect($cleaned)->not->toContain('/NAME/');
    expect($cleaned)->not->toContain('/REMI/');
})->group('phase-2');

it('normalises whitespace runs to a single space', function (): void {
    expect($this->cleaner->clean('STARBUCKS    AMSTERDAM'))->toBe('STARBUCKS AMSTERDAM');
})->group('phase-2');

it('returns the original string when no markers are present', function (): void {
    expect($this->cleaner->clean('SPOTIFY AB'))->toBe('SPOTIFY AB');
})->group('phase-2');
