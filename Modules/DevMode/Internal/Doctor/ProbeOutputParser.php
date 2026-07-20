<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Doctor;

/**
 * Pure parser for `beatrax:doctor`'s stdout.
 *
 * The DoctorCommand emits one line per probe in
 * sprintf('%-24s %-8s %s', $label, $severity, $message) format. The
 * severity column is one of:
 *
 *   - `ok`        → mapped to `pass` (emerald check)
 *   - `warning`   → mapped to `warn` (amber)
 *   - `critical`  → mapped to `fail` (rose X)
 *   - `info`      → kept as `info` (distinct severity bucket for
 *                   informational rows like the ext-imap status)
 *
 * The parser SKIPS the banner / divider / summary lines:
 *   - The first two header lines ("beatrax:doctor" + "-----------------")
 *   - Blank lines
 *   - The final summary like "All checks passed." / "N warning(s)." /
 *     "N blocker(s)…"
 *
 * Pure-PHP / no IO / no DI — the test seeds a representative output
 * string and asserts the returned list.
 *
 * @phpstan-type ProbeRow array{status: 'pass'|'warn'|'fail'|'info', label: string, detail: string}
 */
final class ProbeOutputParser
{
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
            $row = $this->parseLine($line);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Attempt to parse a single line into a ProbeRow. Returns null when
     * the line is a banner / divider / summary / blank.
     *
     * @return ProbeRow|null
     */
    private function parseLine(string $line): ?array
    {
        $trimmed = trim($line);
        if ($trimmed === '') {
            return null;
        }
        if ($trimmed === 'beatrax:doctor' || preg_match('/^-+$/', $trimmed) === 1) {
            return null;
        }
        if (preg_match('/^(All checks passed|\d+ warning|\d+ blocker)/i', $trimmed) === 1) {
            return null;
        }

        // The DoctorCommand format is `%-24s %-8s %s`. Each row has a
        // 24-char label column, a severity token, and the remaining
        // message. Use a regex that tolerates trailing padding.
        if (preg_match('/^(.{1,24}?)\s{2,}(ok|warning|critical|info)\s+(.+)$/i', $line, $matches) !== 1) {
            return null;
        }

        $label = trim($matches[1]);
        $severity = strtolower($matches[2]);
        $detail = trim($matches[3]);

        return [
            'status' => $this->mapSeverity($severity),
            'label' => $label,
            'detail' => $detail,
        ];
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
