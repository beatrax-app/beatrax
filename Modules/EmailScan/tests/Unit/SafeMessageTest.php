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
