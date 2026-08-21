<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Logging;

use Modules\Core\Public\Services\UserDataPathService;
use Throwable;

final readonly class RecentLogEntriesReader
{
    private const int EXCERPT_MAX = 160;

    private const int HREF_CONTAINS_MAX = 80;

    // The widget only needs the tail; 200 lines leaves headroom for folding
    // a stack trace back into the entry that owns it.
    private const int LINE_READ_CAP = 200;

    public function __construct(
        private RedactSecretsProcessor $scrubber,
    ) {}

    /**
     * @return list<array{timestamp: string, severity: string, channel: string, message: string, href: string}>
     */
    public function recent(int $limit): array
    {
        $tail = array_slice($this->readLogLines(), -self::LINE_READ_CAP);

        $entries = [];
        foreach ($tail as $raw) {
            $parsed = $this->parseLine($raw);
            if ($parsed === null) {
                // Folded into the preceding entry so a stack trace's first
                // line stays searchable from that row's deep link.
                if ($entries !== []) {
                    $tailEntry = $entries[array_key_last($entries)];
                    $tailEntry['message'] = $tailEntry['message']."\n".$raw;
                    $entries[array_key_last($entries)] = $tailEntry;
                }

                continue;
            }
            $entries[] = $parsed;
        }

        $recent = array_slice($entries, -$limit);

        $out = [];
        foreach ($recent as $entry) {
            $scrubbed = $this->scrubber->scrub($entry['message']);
            $excerpt = $this->truncate($scrubbed, self::EXCERPT_MAX, appendEllipsis: true);
            // /dev/logs matches `contains` literally against the source line,
            // where no ellipsis exists — hence no ellipsis here either.
            $hrefContains = $this->truncate($scrubbed, self::HREF_CONTAINS_MAX, appendEllipsis: false);
            $out[] = [
                'timestamp' => $entry['timestamp'],
                'severity' => $entry['severity'],
                'channel' => $entry['channel'],
                'message' => $excerpt,
                'href' => '/dev/logs?severities='.rawurlencode($entry['severity'])
                    .'&contains='.rawurlencode($hrefContains),
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function readLogLines(): array
    {
        $path = UserDataPathService::dailyLogFile();
        if (! is_file($path)) {
            return [];
        }

        try {
            $all = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        } catch (Throwable) {
            return [];
        }

        return is_array($all) ? $all : [];
    }

    // null means "not a log-header line"; the caller folds those forward.
    /**
     * @return ?array{timestamp: string, severity: string, channel: string, message: string}
     */
    private function parseLine(string $raw): ?array
    {
        if (preg_match('/^\[([^\]]+)\]\s+([a-z0-9_]+)\.([A-Z]+):\s*(.*)$/i', $raw, $matches) !== 1) {
            return null;
        }

        return [
            'timestamp' => $matches[1],
            'channel' => $matches[2],
            'severity' => strtoupper($matches[3]),
            'message' => $matches[4],
        ];
    }

    /**
     * @param  bool  $appendEllipsis  false returns the prefix verbatim, as a
     *                                literal substring of the source line.
     */
    private function truncate(string $value, int $max, bool $appendEllipsis): string
    {
        $single = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        if (mb_strlen($single) <= $max) {
            return $single;
        }

        if ($appendEllipsis) {
            return mb_substr($single, 0, $max - 1).'…';
        }

        return mb_substr($single, 0, $max);
    }
}
