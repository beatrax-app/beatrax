<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Doctor;

/**
 * @phpstan-type ProbeRow array{status: 'pass'|'warn'|'fail'|'info', label: string, detail: string}
 */
final class ProbeOutputParser
{
    // DoctorCommand emits every row as `%-24s %-8s %s`, and both widths are
    // load-bearing: a real severity starts at column 25 or later and carries
    // its own padding. A continuation line from a multi-line probe message
    // satisfies neither, which is how the two are told apart.
    private const int LABEL_WIDTH = 24;

    private const int SEVERITY_WIDTH = 8;

    private const string ROW_PATTERN = '/^(\S.*?) +(ok|warning|critical|info) +(.*)$/';

    /**
     * @return list<ProbeRow>
     */
    public function parse(string $output): array
    {
        if ($output === '') {
            return [];
        }

        $rows = [];
        $lines = preg_split('/\r?\n/', $output);
        if ($lines === false) {
            return [];
        }

        foreach ($lines as $line) {
            if ($this->isChrome(trim($line))) {
                continue;
            }

            $row = $this->parseRow($line);
            if ($row !== null) {
                $rows[] = $row;

                continue;
            }

            // A line the shape rejects is the rest of the message above it, and
            // the rest is often the half that says what went wrong: the sqlite3
            // probe's own error text ran to a second line and only the first
            // reached the panel.
            $carried = array_key_last($rows);
            if ($carried !== null) {
                $rows[$carried]['detail'] = rtrim($rows[$carried]['detail'])."\n".trim($line);
            }
        }

        return $rows;
    }

    /**
     * @return ProbeRow|null
     */
    private function parseRow(string $line): ?array
    {
        if (preg_match(self::ROW_PATTERN, $line, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $severity = $matches[2][0];
        $severityOffset = $matches[2][1];
        $detailOffset = $matches[3][1];

        $paddedToColumn = $severityOffset >= self::LABEL_WIDTH + 1;
        $gap = $detailOffset - ($severityOffset + strlen($severity));
        $paddedSeverity = $gap === self::SEVERITY_WIDTH - strlen($severity) + 1;

        if (! $paddedToColumn || ! $paddedSeverity) {
            return null;
        }

        return [
            'status' => $this->mapSeverity($severity),
            'label' => trim($matches[1][0]),
            'detail' => trim($matches[3][0]),
        ];
    }

    private function isChrome(string $trimmed): bool
    {
        return $trimmed === ''
            || $trimmed === 'beatrax:doctor'
            || preg_match('/^-+$/', $trimmed) === 1
            || preg_match('/^(All checks passed|\d+ warning|\d+ blocker)/i', $trimmed) === 1;
    }

    /**
     * @return 'pass'|'warn'|'fail'|'info'
     */
    private function mapSeverity(string $severity): string
    {
        return match ($severity) {
            'ok' => 'pass',
            'warning' => 'warn',
            'critical' => 'fail',
            default => 'info',
        };
    }
}
