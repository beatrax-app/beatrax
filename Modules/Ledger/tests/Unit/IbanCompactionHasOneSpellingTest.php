<?php

declare(strict_types=1);

use Modules\Ledger\Public\Services\CounterpartyKey;

// Two questions about one string, kept apart on purpose. forIban() feeds a
// keyed HMAC, so normalizeIban() is the byte the ledger is already keyed
// under and interior spacing belongs to it. compactIban() answers the other
// one — is this an IBAN, and which characters does the triage card mask.

it('compacts every spelling of one IBAN to the same bytes', function (string $written, string $compact): void {
    expect(CounterpartyKey::compactIban($written))->toBe($compact);
})->with([
    ['NL91ABNA0417164300', 'NL91ABNA0417164300'],
    ['nl91abna0417164300', 'NL91ABNA0417164300'],
    ['Nl91AbNa0417164300', 'NL91ABNA0417164300'],
    ['NL91 ABNA 0417 1643 00', 'NL91ABNA0417164300'],
    ['nl91 abna 0417 1643 00', 'NL91ABNA0417164300'],
    ['  NL91 ABNA 0417 1643 00  ', 'NL91ABNA0417164300'],
    ["NL91\tABNA\n0417 1643 00", 'NL91ABNA0417164300'],
    ["NL91\u{00A0}ABNA\u{00A0}0417\u{00A0}1643\u{00A0}00", 'NL91ABNA0417164300'],
    ["NL91\u{2009}ABNA\u{2009}0417\u{2009}1643\u{2009}00", 'NL91ABNA0417164300'],
    ['', ''],
    ['   ', ''],
]);

it('keeps the blind-index input byte-for-byte what it already was', function (string $written, string $normalized): void {
    expect(CounterpartyKey::normalizeIban($written))->toBe($normalized);
})->with([
    ['NL91ABNA0417164300', 'NL91ABNA0417164300'],
    ['nl91abna0417164300', 'NL91ABNA0417164300'],
    ['Nl91AbNa0417164300', 'NL91ABNA0417164300'],
    ['NL91 ABNA 0417 1643 00', 'NL91 ABNA 0417 1643 00'],
    ['nl91 abna 0417 1643 00', 'NL91 ABNA 0417 1643 00'],
    ['  NL91ABNA0417164300  ', 'NL91ABNA0417164300'],
    ["NL91\u{00A0}ABNA0417164300", "NL91\u{00A0}ABNA0417164300"],
    ['', ''],
]);

it('does not let compaction stand in for the blind-index spelling', function (): void {
    $spaced = 'NL91 ABNA 0417 1643 00';

    expect(CounterpartyKey::normalizeIban($spaced))->toBe($spaced)
        ->and(CounterpartyKey::compactIban($spaced))->toBe('NL91ABNA0417164300');
});
