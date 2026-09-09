<?php

declare(strict_types=1);

use Modules\Reports\Internal\Enums\ReportGranularity;
use Modules\Reports\Internal\Support\ReportDefinitionFactory;
use Modules\Reports\Internal\Support\ReportVocabulary;

// A STORED granularity outside the set is rejected rather than defaulted, and
// the two boundaries that read one are not the same boundary. A
// word in the address bar is the reader's, and coercing it to the default is
// what stops a crafted ?gran= from reaching a match() with no arm. A word in the
// saved-report column was written by something — the column is synced, so a peer
// on a newer build is the realistic author — and answering "monthly" for it is
// this build deciding what that peer meant.

it('rejects a stored grouping this build does not know', function (string $stored): void {
    $definition = ReportDefinitionFactory::fromStored(['granularity' => $stored]);

    expect($definition->granularity)->toBeNull();
})->with([
    'a grouping a later build added' => ['quarterly'],
    'the series cadence, which is a different vocabulary' => ['daily'],
    'a word that is not a grouping at all' => ['<script>'],
    'the empty string' => [''],
]);

it('reads a stored grouping this build does know', function (string $stored, ReportGranularity $expected): void {
    expect(ReportDefinitionFactory::fromStored(['granularity' => $stored])->granularity)->toBe($expected);
})->with([
    'monthly' => ['monthly', ReportGranularity::Monthly],
    'weekly' => ['weekly', ReportGranularity::Weekly],
]);

// The other half of the split, and the reason the first half cannot simply be
// applied everywhere: this rail is reader-supplied and an unknown word here used
// to 500 the page.
it('still defaults a grouping the reader supplied', function (?string $supplied): void {
    expect(ReportVocabulary::granularity($supplied))->toBe(ReportGranularity::Monthly);
})->with([
    'a word no build has' => ['quarterly'],
    'nothing at all' => [null],
    'the empty string' => [''],
]);

// A word this build cannot read and a column that never held one come to the
// same answer on purpose: neither is a grouping this build may act on, and the
// requirement is about not acting, not about telling the two apart.
it('answers a rejected grouping the way it answers one that was never stored', function (): void {
    expect(ReportDefinitionFactory::fromStored(['granularity' => 'quarterly'])->granularity)
        ->toBe(ReportDefinitionFactory::fromStored([])->granularity)
        ->and(ReportVocabulary::storedGranularity('quarterly'))->toBeNull()
        ->and(ReportVocabulary::storedGranularity('weekly'))->toBe(ReportGranularity::Weekly);
});
