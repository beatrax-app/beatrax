<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Logging;

use SplFileObject;
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
        private ActiveLogFile $file,
    ) {}

    /**
     * @return list<array{timestamp: string, severity: string, channel: string, message: string, href: string}>
     */
    public function recent(int $limit): array
    {
        $tail = $this->tailLines();

        $entries = [];
        foreach ($tail as $raw) {
            $parsed = $this->parseLine($raw);
            if ($parsed === null) {
                // Folded into the preceding entry so a stack trace's first
                // line stays searchable from that row's deep link.
                if ($entries !== []) {
                    $tailEntry = array_last($entries);
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
            $excerpt = $this->truncate($scrubbed, self::EXCERPT_MAX);
            $hrefContains = $this->hrefNeedle($scrubbed, self::HREF_CONTAINS_MAX);
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

    // Line-by-line via SplFileObject rather than file(), for the reason its
    // sibling LogFileStats gives: this pane backs /dev, the page opened
    // BECAUSE something is wrong, and a 32 MB daily log loaded whole took the
    // request's peak memory from 26 MB to 97 MB.
    /**
     * @return list<string>
     */
    private function tailLines(): array
    {
        $path = $this->file->path();
        if (! is_file($path)) {
            return [];
        }

        $window = [];

        try {
            $file = new SplFileObject($path, 'r');
            $file->setFlags(SplFileObject::DROP_NEW_LINE | SplFileObject::SKIP_EMPTY);

            foreach ($file as $line) {
                if (! is_string($line) || $line === '') {
                    continue;
                }
                $window[] = $line;
                // Trimmed in blocks rather than per line: array_shift on every
                // line is O(cap) per line over the whole file.
                if (count($window) > 2 * self::LINE_READ_CAP) {
                    $window = array_slice($window, -self::LINE_READ_CAP);
                }
            }
        } catch (Throwable) {
            return array_slice($window, -self::LINE_READ_CAP);
        }

        return array_slice($window, -self::LINE_READ_CAP);
    }

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

    // The dashboard row is one line, so runs of whitespace collapse here —
    // which is exactly why the href needle below cannot reuse it.
    private function truncate(string $value, int $max): string
    {
        $single = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        if (mb_strlen($single) <= $max) {
            return $single;
        }

        return mb_substr($single, 0, $max - 1).'…';
    }

    // /dev/logs filters with a literal `includes()`, so the needle must be a
    // verbatim prefix: collapsing whitespace turned `PHP Warning:  Undefined
    // array key` — PHP's own two-space shape — into a needle no line holds.
    // Folded continuation lines are cut because the tailer rows them apart.
    private function hrefNeedle(string $message, int $max): string
    {
        $ownLine = explode("\n", $message, 2)[0];

        return rtrim(mb_substr($ownLine, 0, $max));
    }
}
