<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Logging;

use Modules\Core\Public\Services\UserDataPathService;
use Throwable;

/**
 * @link ../../../../.docs/features/dev-mode/architecture.md
 */
final readonly class RecentLogEntriesReader
{
    // The href carries the untruncated start of the message so the
    // deep-link's `contains` filter still matches the source line even
    // after the visible excerpt is truncated to this length.
    private const int EXCERPT_MAX = 160;

    private const int HREF_CONTAINS_MAX = 80;

    // Caps the raw-line read so a malformed daily log file (single 50 MB
    // line) can't stall the dashboard render; the widget only needs the
    // tail, and 200 lines is generous headroom for folding stack traces.
    private const int LINE_READ_CAP = 200;

    public function __construct(
        private RedactSecretsProcessor $scrubber,
    ) {}

    /**
     * @return list<array{timestamp: string, severity: string, channel: string, message: string, href: string}>
     */
    public function recent(int $limit): array
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
        if (! is_array($all)) {
            return [];
        }

        $tail = array_slice($all, -self::LINE_READ_CAP);

        $entries = [];
        foreach ($tail as $raw) {
            $parsed = $this->parseLine($raw);
            if ($parsed === null) {
                // Continuation line — fold into the preceding entry's
                // message so a stack trace's first line is searchable
                // from the dashboard row's deep-link.
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
            // The href's `contains` filter is a literal substring match
            // against the source log line at /dev/logs, so it must NOT
            // append '…' the way the user-facing excerpt does — the
            // ellipsis never appears in the source data.
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

    // Returns null when the line does not match the standard format —
    // the caller treats those as continuation lines.
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
     * @param  bool  $appendEllipsis  When true, replace the final
     *                                character on truncation with
     *                                '…' so the user-facing excerpt
     *                                signals "more here". When false,
     *                                the truncated prefix is returned
     *                                verbatim — required for the
     *                                deep-link href whose `contains`
     *                                filter is a literal substring
     *                                match against the source line.
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
