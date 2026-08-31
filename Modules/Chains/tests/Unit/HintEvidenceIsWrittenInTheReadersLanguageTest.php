<?php

declare(strict_types=1);

use Modules\Chains\Internal\Presentation\HintEvidenceSummary;
use Modules\Chains\Public\Enums\ChainLinkKind;
use Modules\Core\Public\Enums\Locale;

// Every line under a hint was an English literal built in PHP, with the stored
// token ("exceeded") printed verbatim beside it, and the delta rendered signed
// against a convention only the resolver knew.

function hintEvidenceLines(array $evidence, string $kind = 'ics_bulk_settle'): array
{
    /** @var HintEvidenceSummary $summary */
    $summary = app(HintEvidenceSummary::class);

    return $summary->forHint($kind, (string) json_encode($evidence), 'EUR');
}

it('names the tolerance in words rather than printing the token it stored', function (): void {
    $lines = hintEvidenceLines([
        'tolerance_used' => 'exceeded',
        'unaccounted_delta_minor' => -7413,
        'covered_count' => 54,
        'statement_id' => 12,
    ]);

    expect($lines)->toContain('Tolerance: outside the allowance');
    foreach ($lines as $line) {
        expect($line)->not->toContain('exceeded');
    }
});

it('says which way the settlement missed instead of a signed number', function (): void {
    expect(hintEvidenceLines(['unaccounted_delta_minor' => 668]))
        ->toContain('Overpaid by €6.68');

    expect(hintEvidenceLines(['unaccounted_delta_minor' => -668]))
        ->toContain('Short by €6.68');

    expect(hintEvidenceLines(['unaccounted_delta_minor' => 0]))
        ->toContain('Balances exactly');
});

it('renders every hint line through the reader s locale', function (): void {
    app('translator')->setLocale(Locale::Nl->value);

    $lines = hintEvidenceLines([
        'tolerance_used' => 'exceeded',
        'unaccounted_delta_minor' => -7413,
        'covered_count' => 54,
        'statement_id' => 12,
    ]);

    expect($lines)->toContain('Tolerantie: buiten de marge');
    expect($lines)->toContain('Gedekte transacties: 54');
    expect($lines)->toContain('Kaartafschrift #12');

    expect(hintEvidenceLines(['card_last4' => '4242'], ChainLinkKind::FundedByCardHint->value))
        ->toContain('Kaart eindigend op 4242');
    expect(hintEvidenceLines(['original_reference_id' => 'ORD-9'], ChainLinkKind::RefundOfHint->value))
        ->toContain('Oorspronkelijke bestelreferentie: ORD-9');

    app('translator')->setLocale(Locale::En->value);
});
