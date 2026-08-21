<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\Mt940Tag61Parser;
use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;

beforeEach(function (): void {
    $this->parser = $this->app->make(Mt940Tag61Parser::class);
});

it('parses a basic credit line with SWIFT status C', function (): void {
    $parsed = $this->parser->parse('2604010401C100,00NTRFREF-001//BANK-REF-001');

    expect($parsed->valueDate->toDateString())->toBe('2026-04-01');
    expect($parsed->entryDate?->toDateString())->toBe('2026-04-01');
    expect($parsed->status)->toBe('C');
    expect($parsed->amountMinor)->toBe(10000);
    expect($parsed->transactionTypeCode)->toBe('NTRF');
    expect($parsed->customerReference)->toBe('REF-001');
    expect($parsed->bankReference)->toBe('BANK-REF-001');
})->group('phase-2');

it('parses a debit line with SWIFT status D and produces a negative amount', function (): void {
    $parsed = $this->parser->parse('2604020402D50,29NMSCFEE-001');

    expect($parsed->status)->toBe('D');
    expect($parsed->amountMinor)->toBe(-5029);
    expect($parsed->transactionTypeCode)->toBe('NMSC');
    expect($parsed->customerReference)->toBe('FEE-001');
})->group('phase-2');

it('handles RC (reversal of credit) as debit-like with a negative amount', function (): void {
    $parsed = $this->parser->parse('2604030403RC100,00NREFREVERSAL-001');

    expect($parsed->status)->toBe('RC');
    expect($parsed->amountMinor)->toBe(-10000);
})->group('phase-2');

it('handles RD (reversal of debit) as credit-like with a positive amount', function (): void {
    $parsed = $this->parser->parse('2604040404RD50,00NREFREVERSAL-002');

    expect($parsed->status)->toBe('RD');
    expect($parsed->amountMinor)->toBe(5000);
})->group('phase-2');

it('handles the ASN 34-char customer reference variant (longer than SWIFT 16)', function (): void {
    $parsed = $this->parser->parse('2604050405C1000,00NTRFNL00BANK0001020304BENEFICIARYREF//ASN-BANKREF');

    expect(strlen($parsed->customerReference ?? ''))->toBeGreaterThan(16);
    expect($parsed->customerReference)->toBe('NL00BANK0001020304BENEFICIARYREF');
    expect($parsed->bankReference)->toBe('ASN-BANKREF');
})->group('phase-2');

it('throws InvalidAmountException on a malformed amount', function (): void {
    expect(fn () => $this->parser->parse('2604010401Cnot-a-number-NTRF'))
        ->toThrow(InvalidAmountException::class);
})->group('phase-2');

it('treats absent entry date as null while keeping the value date populated', function (): void {
    $parsed = $this->parser->parse('260401C100,00NTRFREF');

    expect($parsed->valueDate->toDateString())->toBe('2026-04-01');
    expect($parsed->entryDate)->toBeNull();
})->group('phase-2');

it('resolves the entry-date year via the SWIFT year-rollover rule when entry month > value month', function (): void {
    // Value date 2026-01-02 with entry date 12-31 means the entry happened
    // on the previous calendar year's last day.
    $parsed = $this->parser->parse('2601021231C100,00NTRFREF-XMAS');

    expect($parsed->valueDate->toDateString())->toBe('2026-01-02');
    expect($parsed->entryDate?->toDateString())->toBe('2025-12-31');
})->group('phase-2');

it('keeps the entry-date year equal to the value-date year when entry month <= value month', function (): void {
    $parsed = $this->parser->parse('2604020401C100,00NTRFREF-SAME');

    expect($parsed->valueDate->toDateString())->toBe('2026-04-02');
    expect($parsed->entryDate?->toDateString())->toBe('2026-04-01');
})->group('phase-2');
