<?php

declare(strict_types=1);

use Modules\Import\Internal\Enums\ConfirmRefusal;
use Modules\Import\Public\Exceptions\ImportNotConfirmableException;

// OpenBankingSyncRunner decides on this alone whether a refused window goes back
// to the queue's retry envelope. The enum is walked rather than sampled: the
// predicate answers by identity, so a case added without a decision would
// silently answer "not worth retrying" and cost that window its backoff.
it('has a decided answer for every refusal there is', function (): void {
    $expected = [
        ConfirmRefusal::AccountsToName->value => false,
        ConfirmRefusal::NothingImportable->value => false,
        ConfirmRefusal::FileDidNotReadInFull->value => true,
    ];

    $actual = [];
    foreach (ConfirmRefusal::cases() as $case) {
        $actual[$case->value] = $case->anotherReadCouldDiffer();
    }

    expect($actual)->toBe($expected);
});

// Read off the exception rather than the enum, because that is the only shape
// the deciding caller can see: ConfirmRefusal is Import-internal, so a module
// that catches this may not name it.
it('carries the same answer out to the caller that catches it', function (): void {
    $stoppedShort = new ImportNotConfirmableException(41, ConfirmRefusal::FileDidNotReadInFull);
    $unnamedAccount = new ImportNotConfirmableException(42, ConfirmRefusal::AccountsToName);

    expect($stoppedShort->anotherReadCouldDiffer())->toBeTrue()
        ->and($unnamedAccount->anotherReadCouldDiffer())->toBeFalse();
});

// The message is what a maintainer reads in the log, and a refusal naming the
// wrong cause is the defect this whole seam exists to stop.
it('says which of the three refusals it was', function (): void {
    expect((new ImportNotConfirmableException(7, ConfirmRefusal::FileDidNotReadInFull))->getMessage())
        ->toBe('Import run 7 cannot be confirmed: reading it stopped before the end, so whatever it holds past that point was never seen.');
});
