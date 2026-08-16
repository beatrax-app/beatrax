<?php

declare(strict_types=1);

use Modules\Auth\Internal\Recovery\RecoveryCodeGenerator;
use Modules\Auth\Public\Recovery\RecoveryCodeFormatter;

/*
 * Unit coverage for the recovery-code primitives: the phone-readable
 * code generator (CSPRNG-backed, ambiguous characters excluded) and the
 * downloadable `.txt` formatter.
 */

/** Five groups of four phone-readable characters joined by hyphens. */
const RECOVERY_CODE_PATTERN = '/^[A-NP-Z2-9]{4}(-[A-NP-Z2-9]{4}){4}$/';

it('generates a code matching the five-group hyphenated pattern', function (): void {
    $generator = new RecoveryCodeGenerator;

    expect($generator->generate())->toMatch(RECOVERY_CODE_PATTERN);
});

it('generates 1000 unique codes that all match the pattern', function (): void {
    $generator = new RecoveryCodeGenerator;

    $seen = [];
    for ($i = 0; $i < 1000; $i++) {
        $code = $generator->generate();
        expect($code)->toMatch(RECOVERY_CODE_PATTERN);
        $seen[$code] = true;
    }

    expect(count($seen))->toBe(1000);
});

it('never emits the ambiguous characters O, I, L, 0 or 1', function (): void {
    $generator = new RecoveryCodeGenerator;

    for ($i = 0; $i < 500; $i++) {
        $code = $generator->generate();
        foreach (['O', 'I', 'L', '0', '1'] as $ambiguous) {
            expect(str_contains($code, $ambiguous))->toBeFalse();
        }
    }
});

it('formats codes one per line with no header and no trailing newline', function (): void {
    $formatter = new RecoveryCodeFormatter;

    $result = $formatter->format(['ABCD-2222-3333-4444-5555', 'WXYZ-6666-7777-8888-9999']);

    expect($result)->toBe("ABCD-2222-3333-4444-5555\nWXYZ-6666-7777-8888-9999");
});

it('builds a lowercase filename from the username', function (): void {
    $formatter = new RecoveryCodeFormatter;

    expect($formatter->filenameFor('Alice'))->toBe('beatrax-recovery-codes-alice.txt');
    expect($formatter->filenameFor('  BOB  '))->toBe('beatrax-recovery-codes-bob.txt');
});
