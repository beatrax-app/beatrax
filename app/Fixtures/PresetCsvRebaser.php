<?php

declare(strict_types=1);

namespace App\Fixtures;

use Carbon\CarbonImmutable;
use Modules\Core\Public\Support\SafeDate;
use Modules\Ingestion\Public\Dto\CsvPreset;
use Modules\Ingestion\Public\Dto\PositionalCsvPreset;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;

/**
 * @link ../../.docs/local_development/rebasing-a-statement-fixture.md#csv
 */
final readonly class PresetCsvRebaser implements RebasesStatementDates
{
    private const string LINE_SPLIT = '/(\r\n|\r|\n)/';

    public function __construct(private CsvPresetRegistry $presets) {}

    public function handles(string $path, string $contents): bool
    {
        return preg_match('/\.csv$/i', $path) === 1 && $this->match($contents) !== null;
    }

    public function format(string $contents): string
    {
        return $this->preset($contents)->format;
    }

    // The posted-date column only. A statement's other date columns can run past
    // it -- a value date, a statement closing date -- and anchoring on those
    // lands the ROWS a month short of the window they were shifted to reach.
    public function newestDate(string $contents): ?CarbonImmutable
    {
        $preset = $this->preset($contents);
        $column = $this->postedColumn($contents, $preset);
        $dates = [];

        foreach ($this->split($contents) as $index => $piece) {
            if (! $this->isDataRow($index)) {
                continue;
            }

            $cells = $this->cells($piece, $preset->delimiter);
            $date = $this->asDate($cells[$column] ?? '', $preset->dateFormat);
            if ($date instanceof CarbonImmutable) {
                $dates[] = $date;
            }
        }

        return $this->latest($dates);
    }

    private function postedColumn(string $contents, CsvPreset|PositionalCsvPreset $preset): int
    {
        if ($preset instanceof PositionalCsvPreset) {
            return $preset->postedDateColumn;
        }

        $header = $this->cells($this->split($contents)[0] ?? '', $preset->delimiter);
        $column = array_search($preset->dateHeader, $header, strict: true);

        if (! is_int($column)) {
            throw new StatementRebaseFailed(sprintf('Header row carries no %s column.', $preset->dateHeader));
        }

        return $column;
    }

    public function rebase(string $contents, MonthShift $shift): StatementRebaseResult
    {
        $preset = $this->preset($contents);
        $before = $this->dates($contents, $preset);

        if ($before === []) {
            throw new StatementRebaseFailed(sprintf(
                'No cell in this CSV parses as a %s date, so there is nothing to rebase.',
                $preset->dateFormat,
            ));
        }

        $after = [];
        $pieces = $this->split($contents);

        foreach ($pieces as $index => $piece) {
            if (! $this->isDataRow($index)) {
                continue;
            }

            $cells = $this->cells($piece, $preset->delimiter);
            $changed = false;

            foreach ($cells as $position => $cell) {
                $date = $this->asDate($cell, $preset->dateFormat);
                if (! $date instanceof CarbonImmutable) {
                    continue;
                }

                $shifted = $shift->apply($date);
                $after[] = $shifted;
                $cells[$position] = $shifted->format($preset->dateFormat);
                $changed = true;
            }

            if ($changed) {
                $pieces[$index] = implode($preset->delimiter, $cells);
            }
        }

        return new StatementRebaseResult(
            contents: implode('', $pieces),
            format: $preset->format,
            months: $shift->months,
            oldestBefore: $this->earliestOrFail($before),
            newestBefore: $this->latestOrFail($before),
            oldestAfter: $this->earliestOrFail($after),
            newestAfter: $this->latestOrFail($after),
            datesRewritten: count($after),
        );
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function dates(string $contents, CsvPreset|PositionalCsvPreset $preset): array
    {
        $dates = [];

        foreach ($this->split($contents) as $index => $piece) {
            if (! $this->isDataRow($index)) {
                continue;
            }

            foreach ($this->cells($piece, $preset->delimiter) as $cell) {
                $date = $this->asDate($cell, $preset->dateFormat);
                if ($date instanceof CarbonImmutable) {
                    $dates[] = $date;
                }
            }
        }

        return $dates;
    }

    // Index 0 is the header row every preset here declares, and odd indices are
    // the captured line terminators rather than lines.
    private function isDataRow(int $index): bool
    {
        return $index > 0 && $index % 2 === 0;
    }

    // A format round-trip on top of SafeDate, which on its own admits a cell the
    // format only partly explains. Detection decides what gets REWRITTEN here, so
    // a false positive corrupts a reference column that merely looked like a date.
    private function asDate(string $raw, string $format): ?CarbonImmutable
    {
        if ($raw === '') {
            return null;
        }

        $parsed = SafeDate::fromFormatOrNull('!'.$format, $raw);

        return $parsed?->format($format) === $raw ? $parsed : null;
    }

    // Each cell keeps its raw bytes, enclosures included, so re-imploding on the
    // delimiter reproduces an untouched line byte for byte.
    /**
     * @return list<string>
     */
    private function cells(string $line, string $delimiter): array
    {
        $cells = [];
        $start = 0;
        $quoted = false;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            if ($line[$i] === '"') {
                $quoted = ! $quoted;

                continue;
            }

            if (! $quoted && $line[$i] === $delimiter) {
                $cells[] = substr($line, $start, $i - $start);
                $start = $i + 1;
            }
        }

        $cells[] = substr($line, $start);

        return $cells;
    }

    // Odd indices hold the terminator that followed the line before them, so a
    // CRLF export stays CRLF and a file with no trailing newline gains none.
    /**
     * @return list<string>
     */
    private function split(string $contents): array
    {
        $pieces = preg_split(self::LINE_SPLIT, $contents, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($pieces === false) {
            throw new StatementRebaseFailed('Could not split the CSV into lines.');
        }

        return $pieces;
    }

    private function preset(string $contents): CsvPreset|PositionalCsvPreset
    {
        $preset = $this->match($contents);
        if ($preset === null) {
            throw new StatementRebaseFailed('No CSV preset matches this header row.');
        }

        return $preset;
    }

    private function match(string $contents): CsvPreset|PositionalCsvPreset|null
    {
        $pieces = $this->split($contents);
        $firstLine = $pieces[0] ?? '';

        foreach ($this->presets->allLayouts() as $preset) {
            $header = $this->cells($firstLine, $preset->delimiter);

            $matches = $preset instanceof PositionalCsvPreset
                ? $this->matchesByPosition($preset, $header)
                : $this->namesEveryColumn($preset->headerSignature, $header);

            if ($matches) {
                return $preset;
            }
        }

        return null;
    }

    // A headerless export is identified by its column count and the leading
    // cells it does carry, so both have to hold: the count alone matches every
    // other export of the same width.
    /**
     * @param  list<string>  $header
     */
    private function matchesByPosition(PositionalCsvPreset $preset, array $header): bool
    {
        if (! in_array(count($header), $preset->acceptedColumnCounts, strict: true)) {
            return false;
        }

        foreach ($preset->headerSignature as $position => $expected) {
            if (($header[$position] ?? null) !== $expected) {
                return false;
            }
        }

        return true;
    }

    // Presence, not position: a named export moves and adds columns between
    // releases, so the signature is the set of headers that must be somewhere
    // in the row rather than the row itself.
    /**
     * @param  list<string>  $signature
     * @param  list<string>  $header
     */
    private function namesEveryColumn(array $signature, array $header): bool
    {
        foreach ($signature as $expected) {
            if (! in_array($expected, $header, strict: true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<CarbonImmutable>  $dates
     */
    private function latest(array $dates): ?CarbonImmutable
    {
        $latest = null;
        foreach ($dates as $date) {
            if ($latest === null || $date->greaterThan($latest)) {
                $latest = $date;
            }
        }

        return $latest;
    }

    /**
     * @param  list<CarbonImmutable>  $dates
     */
    private function latestOrFail(array $dates): CarbonImmutable
    {
        return $this->latest($dates) ?? throw new StatementRebaseFailed('The rebase rewrote no date cells.');
    }

    /**
     * @param  list<CarbonImmutable>  $dates
     */
    private function earliestOrFail(array $dates): CarbonImmutable
    {
        $earliest = null;
        foreach ($dates as $date) {
            if ($earliest === null || $date->lessThan($earliest)) {
                $earliest = $date;
            }
        }

        return $earliest ?? throw new StatementRebaseFailed('The rebase rewrote no date cells.');
    }
}
