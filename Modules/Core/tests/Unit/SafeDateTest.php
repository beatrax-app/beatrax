<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Public\Support\SafeDate;

it('parses a date-only field to the start of its day', function (): void {
    expect(SafeDate::parseDayOrNull('2026-05-15')?->toDateTimeString())->toBe('2026-05-15 00:00:00');
});

// The two Livewire pages that used to hold a private copy of this both fed it
// a raw @entangle'd input, and a browser autofill can hand back padding.
it('trims the field before parsing it', function (): void {
    expect(SafeDate::parseDayOrNull("  2026-05-15\n")?->toDateTimeString())->toBe('2026-05-15 00:00:00');
});

it('flattens a time the reader never typed', function (): void {
    expect(SafeDate::parseDayOrNull('2026-05-15 13:45:12')?->toDateTimeString())->toBe('2026-05-15 00:00:00');
});

// CarbonImmutable::parse('') answers NOW, so a blank field would book itself
// today; both callers compare the answer against a period and would have
// silently used the wrong one.
it('answers null for a blank or unparseable field rather than today', function (string $raw): void {
    expect(SafeDate::parseDayOrNull($raw))->toBeNull();
})->with(['', '   ', 'not-a-date']);

it('agrees with parseOrNull on everything but the time', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15 12:00:00'));

    expect(SafeDate::parseDayOrNull('2026-03-02 09:15:00')?->toDateString())
        ->toBe(SafeDate::parseOrNull('2026-03-02 09:15:00')?->toDateString());

    CarbonImmutable::setTestNow();
});
