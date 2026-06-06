<?php

declare(strict_types=1);

namespace Modules\Community\Public\Services;

use Psr\Log\LoggerInterface;

/**
 * Matches a bank-statement description against a corpus pattern.
 *
 * A pattern is one of two kinds, distinguished by an optional `regex:`
 * prefix:
 *
 *   - Literal (default): a case-insensitive SUBSTRING test. `ALBERT HEIJN`
 *     matches `CCV*ALBERT HEIJN AMSTERDAM 1234`.
 *   - Regex: `regex:` followed by a PCRE body (no delimiters). The body is
 *     wrapped in `#...#i` (case-insensitive) and tested against the whole
 *     haystack. `regex:^ALBERT HEIJN \d+$` matches `ALBERT HEIJN 1234` but
 *     not `MIJN ALBERT HEIJN`.
 *
 * A malformed regex never throws: it is logged once at warning level and
 * treated as a non-match, so one bad corpus row can never abort an import
 * or a resolver pass.
 */
final class CorpusPatternMatcher
{
    /** Prefix that marks a pattern as a PCRE body rather than a literal. */
    public const REGEX_PREFIX = 'regex:';

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Returns true when $pattern matches $haystack. $haystack is matched
     * as-is; callers that already upper-cased it still get correct results
     * because the comparison is case-insensitive either way.
     */
    public function matches(string $pattern, string $haystack): bool
    {
        if ($pattern === '' || $haystack === '') {
            return false;
        }

        if (str_starts_with($pattern, self::REGEX_PREFIX)) {
            return $this->matchesRegex(substr($pattern, strlen(self::REGEX_PREFIX)), $haystack, $pattern);
        }

        return mb_stripos($haystack, $pattern) !== false;
    }

    /** True when $pattern is the regex kind. */
    public function isRegex(string $pattern): bool
    {
        return str_starts_with($pattern, self::REGEX_PREFIX);
    }

    private function matchesRegex(string $body, string $haystack, string $original): bool
    {
        if ($body === '') {
            return false;
        }

        $delimited = '#'.str_replace('#', '\#', $body).'#i';

        $result = @preg_match($delimited, $haystack);
        if ($result === false) {
            $this->logger->warning('CorpusPatternMatcher: invalid regex pattern, treated as non-match.', [
                'pattern' => $original,
            ]);

            return false;
        }

        return $result === 1;
    }
}
