<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Doctor;

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
        if ($this->isChrome(trim($line))) {
            return null;
        }

        // DoctorCommand prints rows as `%-24s %-8s %s`, so the label arrives
        // padded out to 24 chars and the regex has to tolerate the padding.
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
