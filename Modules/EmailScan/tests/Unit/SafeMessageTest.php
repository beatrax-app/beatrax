<?php

declare(strict_types=1);

use Modules\EmailScan\Internal\SafeMessage;

it('collapses internal newlines to a single space', function (): void {
    expect(SafeMessage::cap("first line\nsecond line\nthird line"))
        ->toBe('first line second line third line');
});

it('collapses CRLF newlines to a single space', function (): void {
    expect(SafeMessage::cap("a\r\nb"))->toBe('a b');
});

it('collapses runs of mixed whitespace to one space', function (): void {
    expect(SafeMessage::cap("a  \t\n  b"))->toBe('a b');
});

it('caps the output at the default 300 bytes', function (): void {
    $raw = str_repeat('x', 1000);
    $out = SafeMessage::cap($raw);
    expect(strlen($out))->toBe(300);
});

it('honours a custom byte ceiling', function (): void {
    $raw = str_repeat('y', 200);
    expect(strlen(SafeMessage::cap($raw, 50)))->toBe(50);
});

it('returns an empty string unchanged', function (): void {
    expect(SafeMessage::cap(''))->toBe('');
});

it('leaves a short single-line message unchanged', function (): void {
    expect(SafeMessage::cap('invalid_grant: refresh token revoked'))
        ->toBe('invalid_grant: refresh token revoked');
});

// The provider writes the hint in the reader's own language, so the 300th byte
// lands inside a character routinely rather than exceptionally. Swept across
// every alignment, a plain substr split one in three of them and the cut end
// rendered as a lozenge.
it('never cuts a multibyte character in half, at any alignment', function (): void {
    $split = 0;

    for ($pad = 0; $pad <= 20; $pad++) {
        $raw = str_repeat('a', $pad).str_repeat('é', 400);
        $out = SafeMessage::cap($raw);

        expect(mb_check_encoding($out, 'UTF-8'))->toBeTrue(sprintf('pad %s cut a character in half', $pad));
        expect(strlen($out))->toBeLessThanOrEqual(300);

        if (strlen($out) < 300) {
            $split++;
        }
    }

    // The control: a sweep that never reached the boundary would pass the
    // assertion above without testing it.
    expect($split)->toBeGreaterThan(0, 'no alignment in the sweep landed the cut inside a character');
});
