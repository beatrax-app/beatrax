<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Doctor;

// Pure parser for `beatrax:doctor`'s stdout: one line per probe in
// sprintf('%-24s %-8s %s', $label, $severity, $message) format, mapping
// severity ok/warning/critical/info to pass/warn/fail/info. Skips the
// banner, divider, blank, and summary lines.
/**
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
