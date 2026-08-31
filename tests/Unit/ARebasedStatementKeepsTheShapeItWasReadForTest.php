<?php

declare(strict_types=1);

use App\Fixtures\Camt053Rebaser;
use App\Fixtures\MonthShift;
use App\Fixtures\Mt940Rebaser;
use App\Fixtures\PresetCsvRebaser;
use Carbon\CarbonImmutable;

$fixtures = __DIR__.'/../fixtures/';

function rsCsv(): PresetCsvRebaser
{
    return app(PresetCsvRebaser::class);
}

/**
 * @return list<list<string>>
 */
function rsCells(string $csv): array
{
    $rows = [];
    foreach (explode("\n", trim($csv)) as $line) {
        $rows[] = explode(',', $line);
    }

    return $rows;
}

it('rewrites the three ASN date columns and leaves every other cell byte-identical', function () use ($fixtures): void {
    $source = (string) file_get_contents($fixtures.'asn-sample-1.csv');

    $result = rsCsv()->rebase($source, MonthShift::of(4));

    $before = rsCells($source);
    $after = rsCells($result->contents);
    expect($after)->toHaveCount(count($before));

    $changed = [];
    foreach ($before as $row => $cells) {
        expect($after[$row])->toHaveCount(count($cells));
        foreach ($cells as $column => $cell) {
            if ($after[$row][$column] !== $cell) {
                $changed[$column] = true;
            }
        }
    }

    expect(array_keys($changed))->toBe([0, 11, 12]);
    expect($after[0])->toBe($before[0]);
});

it('keeps the day of the month a monthly series is recognised by', function () use ($fixtures): void {
    $source = (string) file_get_contents($fixtures.'asn-sample-1.csv');

    $result = rsCsv()->rebase($source, MonthShift::of(4));

    $days = static function (string $csv): array {
        $days = [];
        foreach (rsCells($csv) as $row => $cells) {
            if ($row > 0 && ($cells[3] ?? '') === 'Bol.com') {
                $days[] = substr($cells[0], 0, 2);
            }
        }
        sort($days);

        return $days;
    };

    expect($days($result->contents))->toBe($days($source));
});

// A March 31st shifted into a thirty-day month has to clamp to the 30th and stay
// in April: overflow rolls it into May and collapses two statement months into
// one, which is what makes a series stop looking monthly.
it('clamps a month-end date instead of rolling it into the next month', function () use ($fixtures): void {
    $source = (string) file_get_contents($fixtures.'asn-month-a-and-b.csv');
    expect($source)->toContain('31-03-2026,');

    $result = rsCsv()->rebase($source, MonthShift::of(1));

    expect($result->contents)->toContain('30-04-2026,');
    expect($result->contents)->not->toContain('01-05-2026,');
    expect($result->newestAfter->toDateString())->toBe('2026-04-30');
});

// Row 10 of the gold fixture is posted on the 5th with a value date on the 1st.
// A rebaser that copied the posted date across all three columns would erase the
// only rows where they disagree.
it('shifts the value date independently of the posted date', function () use ($fixtures): void {
    $source = (string) file_get_contents($fixtures.'asn-sample-1.csv');

    $result = rsCsv()->rebase($source, MonthShift::of(4));

    $offsets = static function (string $csv): array {
        $offsets = [];
        foreach (rsCells($csv) as $row => $cells) {
            if ($row === 0 || ($cells[0] ?? '') === ($cells[12] ?? '')) {
                continue;
            }
            $posted = CarbonImmutable::createFromFormat('!d-m-Y', $cells[0]);
            $value = CarbonImmutable::createFromFormat('!d-m-Y', $cells[12]);
            $offsets[] = $value->diffInDays($posted);
        }

        return $offsets;
    };

    expect($offsets($result->contents))->toBe($offsets($source))->not->toBeEmpty();
});

// The unparseable sentinel is the whole point of the partial-failure fixture, so
// a rebaser that "fixed" it would delete the case the fixture exists for. The
// month-13 date is the sharper half: createFromFormat rolls it forward to
// January without complaint, and a rebaser that took that would write a
// plausible date over a row a test expects to be refused.
it('leaves a cell that is not a date alone', function () use ($fixtures): void {
    $source = (string) file_get_contents($fixtures.'asn-partial-failure.csv');

    $result = rsCsv()->rebase($source, MonthShift::of(6));

    expect($result->contents)->toContain('geen-datum,');

    $impossible = str_replace('geen-datum,', '31-13-2026,', $source);

    expect(rsCsv()->rebase($impossible, MonthShift::of(6))->contents)->toContain('31-13-2026,');
});

it('refuses a CSV no shipped preset explains', function () use ($fixtures): void {
    $path = $fixtures.'unrecognised-headers.csv';

    expect(rsCsv()->handles($path, (string) file_get_contents($path)))->toBeFalse();
});

it('follows summer time when a camt.053 creation stamp crosses the boundary', function () use ($fixtures): void {
    $source = (string) file_get_contents($fixtures.'asn-cross-format/february.camt053.xml');
    expect($source)->toContain('<CreDtTm>2026-05-13T16:13:36.163248858+02:00</CreDtTm>');

    $result = app(Camt053Rebaser::class)->rebase($source, MonthShift::of(6));

    expect($result->contents)->toContain('<CreDtTm>2026-11-13T16:13:36.163248858+01:00</CreDtTm>');
    expect($result->contents)->toContain('<Dt>2026-08-02</Dt>');
});

// The :61: entry date carries no year of its own, so it has to be rebuilt from
// the value date rather than shifted on its own.
it('shifts an MT940 statement line and its yearless entry date together', function () use ($fixtures): void {
    $source = (string) file_get_contents($fixtures.'asn-mt940-sample-1.sta');
    expect($source)->toContain(':61:2602020202D3,99');
    expect($source)->toContain(':60F:C260202EUR');

    $result = app(Mt940Rebaser::class)->rebase($source, MonthShift::of(5));

    expect($result->contents)->toContain(':61:2607020702D3,99');
    expect($result->contents)->toContain(':60F:C260702EUR');
});

// The cross-format pair describes one February twice. A shift derived per file
// has to come out the same for both, or the fingerprints stop lining up and the
// dedup contract the pair exists to prove no longer holds.
it('derives one shift for both halves of the cross-format pair', function () use ($fixtures): void {
    $csv = (string) file_get_contents($fixtures.'asn-cross-format/february.csv');
    $xml = (string) file_get_contents($fixtures.'asn-cross-format/february.camt053.xml');
    $anchor = CarbonImmutable::parse('2026-08-29');

    $fromCsv = MonthShift::intoMonthOf(rsCsv()->newestDate($csv), $anchor);
    $fromXml = MonthShift::intoMonthOf(app(Camt053Rebaser::class)->newestDate($xml), $anchor);

    expect($fromCsv->months)->toBe($fromXml->months);
});

it('lands the newest row in the month of the anchor', function (string $newest, string $anchor, string $expected): void {
    $shift = MonthShift::intoMonthOf(CarbonImmutable::parse($newest), CarbonImmutable::parse($anchor));

    expect($shift->apply(CarbonImmutable::parse($newest))->toDateString())->toBe($expected);
})->with([
    ['2026-04-30', '2026-08-29', '2026-08-30'],
    ['2026-04-30', '2027-02-01', '2027-02-28'],
    ['2026-04-30', '2028-02-15', '2028-02-29'],
    ['2026-03-31', '2026-09-01', '2026-09-30'],
    ['2026-04-30', '2026-01-05', '2026-01-30'],
]);
