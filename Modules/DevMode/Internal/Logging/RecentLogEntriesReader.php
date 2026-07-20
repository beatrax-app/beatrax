<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Logging;

use Modules\Core\Public\Services\UserDataPathService;
use Throwable;

/**
 * Reads the tail of today's daily Laravel log file and folds the
 * raw lines into structured entries matching the Laravel format
 *
 *   [YYYY-MM-DD HH:MM:SS] channel.SEVERITY: message body
 *
 * Continuation lines (stack-trace rows, JSON payload tails) fold
 * into the preceding entry's message. The visible message is
 * truncated to {@see EXCERPT_MAX} characters for compact dashboard
 * rendering. Every returned message is re-run through the on-stream
 * {@see RedactSecretsProcessor} as defense-in-depth on top of the
 * on-write Monolog tap.
 *
 * Each entry carries an `href` pre-filtered deep-link to /dev/logs
 * (severity + contains query params) so the operator can drill from
 * a dashboard row into the live tailer with the matching context.
 */
final readonly class RecentLogEntriesReader
{
    /**
     * Truncate the message excerpt at this length so the dashboard
     * row stays single-line. The href carries the untruncated start
     * of the message so the deep-link's `contains` filter still
     * matches the source line.
     */
    private const int EXCERPT_MAX = 160;

    /**
     * Maximum length of the `contains` value carried in the deep-link
     * href so the URL stays reasonable for tooltip / right-click
     * affordances.
     */
    private const int HREF_CONTAINS_MAX = 80;

    /**
     * Cap the raw-line read so a malformed daily log file (single
     * 50 MB line) does not stall the dashboard render. The dashboard
     * widget only needs the tail; 200 lines is generous headroom for
     * folding even verbose stack-trace continuations into 5 entries.
     */
    private const int LINE_READ_CAP = 200;

    public function __construct(
        private RedactSecretsProcessor $scrubber,
    ) {}

    /**
     * Return the most recent N structured log entries from today's
     * daily file. Empty array when the file is missing, unreadable,
     * or contains zero parseable entries.
     *
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

    /**
     * Parse one Laravel daily-log line into its components. Returns
     * null when the line does not match the standard format — the
     * caller treats those as continuation lines.
     *
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
     * Truncate a string to `max` characters. Multi-line input is
     * collapsed to a single line first so the dashboard row stays
     * one line tall.
     *
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
