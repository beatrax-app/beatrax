<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Public\Support\SafeDate;

it('parses a date-only field to the start of its day', function (): void {
    expect(SafeDate::normalisedDayOrNull('2026-05-15')?->toDateTimeString())->toBe('2026-05-15 00:00:00');
});

// The two Livewire pages that used to hold a private copy of this both fed it
// a raw @entangle'd input, and a browser autofill can hand back padding.
it('trims the field before parsing it', function (): void {
    expect(SafeDate::normalisedDayOrNull("  2026-05-15\n")?->toDateTimeString())->toBe('2026-05-15 00:00:00');
});

it('flattens a time the reader never typed', function (): void {
    expect(SafeDate::normalisedDayOrNull('2026-05-15 13:45:12')?->toDateTimeString())->toBe('2026-05-15 00:00:00');
});

// CarbonImmutable::parse('') answers NOW, so a blank field would book itself
// today; both callers compare the answer against a period and would have
// silently used the wrong one.
it('answers null for a blank or unparseable field rather than today', function (string $raw): void {
    expect(SafeDate::normalisedDayOrNull($raw))->toBeNull();
})->with(['', '   ', 'not-a-date']);

it('agrees with parseOrNull on everything but the time', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15 12:00:00'));

    expect(SafeDate::normalisedDayOrNull('2026-03-02 09:15:00')?->toDateString())
        ->toBe(SafeDate::parseOrNull('2026-03-02 09:15:00')?->toDateString());

    CarbonImmutable::setTestNow();
});

// The refusing half. Every parser in PHP rolls an out-of-range component
// forward, so each of these used to become a different, perfectly storable
// date — and '2026' and 'tomorrow' became today and tomorrow.
it('refuses a day the calendar does not have, an unpadded one and free text', function (string $raw): void {
    expect(SafeDate::dayOrNull($raw))->toBeNull();
})->with([
    '2027-02-29',
    '2026-11-31',
    '2026-02-30',
    '2026-13-01',
    '2026-1-5',
    '2026',
    '2026-06',
    'tomorrow',
    'not-a-date',
    '',
    '   ',
    '2026-05-15 13:45:12',
]);

it('accepts a real day, trimmed, at the start of that day', function (): void {
    expect(SafeDate::dayOrNull("  2026-05-15\n")?->toDateTimeString())->toBe('2026-05-15 00:00:00');
    expect(SafeDate::dayOrNull('2028-02-29')?->toDateString())->toBe('2028-02-29');
});

// The pair is the whole point: the normalising one is still right for a
// machine-emitted string with no Y-m-d shape to check, and wrong for anything
// a reader or a peer supplies. Asserted together so neither drifts alone.
it('normalises where the refusing seam refuses', function (): void {
    expect(SafeDate::normalisedDayOrNull('2027-02-29')?->toDateString())->toBe('2027-03-01');
    expect(SafeDate::dayOrNull('2027-02-29'))->toBeNull();
});
