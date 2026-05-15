<?php

declare(strict_types=1);

use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;

/*
 * Failing scaffolds for the ICS PDF adapter. Driven Green by plan 03-02.
 *
 * Every case explicitly fails with the canonical scaffold-failure pattern
 * so the focused `--group=phase-3` run is Red until the adapter lands.
 * Skipped tests would slip past the VALIDATION.md Dimension 8 Nyquist gate.
 */

beforeEach(function (): void {
    $this->resolver = new class implements AccountResolver
    {
        /** @var array<int, string> */
        public array $askedFor = [];

        public function resolve(string $iban): AccountResolution
        {
            $this->askedFor[] = $iban;

            return AccountResolution::unknown($iban);
        }
    };
});

it('reports its stable format identifier as ics-pdf', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-02');
})->group('phase-3');

it('parses the redacted .txt fixture into the expected SourceTransactionDto stream', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-02');
})->group('phase-3');

it('preserves the per-transaction extracted-text block in rawPayload under the ics-pdf format discriminator', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-02');
})->group('phase-3');

it('emits monotonically increasing sourceRowIndex starting at zero', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-02');
})->group('phase-3');

it('rejects a file that fails the magic-byte sniff before reading any transaction', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-02');
})->group('phase-3');

it('yields native + settled pair for a foreign-currency row', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-02');
})->group('phase-3');

it('yields settledAmountMinor = null for an EUR-native row', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-02');
})->group('phase-3');

it('discards card-number text from the per-transaction block before writing rawPayload', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-02');
})->group('phase-3');

it('exposes statement-summary tokens via statementMetadata() after parse() completes', function (): void {
    // The empirical ICS PDF uses revolving-credit nomenclature
    // (`Vorig openstaand saldo`, `Totaal ontvangen betalingen`,
    //  `Totaal nieuwe uitgaven`, `Nieuw openstaand saldo`,
    //  `Bestedingslimiet`, `Minimaal te betalen bedrag`) — NOT the
    // current-account tokens originally guessed in the phase context.
    // Plan 03-02 implements statementMetadata() against the empirical
    // token set; see Modules/Ingestion/tests/fixtures/ics/ics-sample-1.md
    // (Statement summary tokens section) for the verbatim line offsets.
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-02');
})->group('phase-3');

it('registers under the ics-pdf key in the SourceAdapterRegistry', function (): void {
    expect(true)->toBe(false, 'scaffold — implemented in plan 03-02');
})->group('phase-3');
