<?php

declare(strict_types=1);

namespace Modules\Community\Public\Services;

use Psr\Log\LoggerInterface;

/**
 * @link ../../../../.docs/features/community/architecture.md
 */
final class CorpusPatternMatcher
{
    public const REGEX_PREFIX = 'regex:';

    // A corpus regex body longer than this is refused before it runs — real
    // patterns are short merchant tokens. The cap does NOT bound backtracking
    // (a short `(a+)+$` is still pathological), so ReDoS safety comes from the
    // explicit budget in matchWithinBudget(), not this length limit.
    public const MAX_REGEX_BODY_LENGTH = 256;

    // Catastrophic backtracking on a corpus-supplied pattern (a hostile input)
    // is bounded here rather than left to php.ini's 1M default: a match that
    // exceeds this many steps aborts (preg_match returns false) and is treated
    // as a non-match. Far above any legitimate merchant-token pattern's needs.
    private const PCRE_BACKTRACK_BUDGET = 100_000;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function matches(string $pattern, string $haystack): bool
    {
        // $haystack is matched as-is; a caller that already upper-cased it
        // still gets correct results, since the comparison below is
        // case-insensitive either way.
        if ($pattern === '' || $haystack === '') {
            return false;
        }

        if (str_starts_with($pattern, self::REGEX_PREFIX)) {
            return $this->matchesRegex(substr($pattern, strlen(self::REGEX_PREFIX)), $haystack, $pattern);
        }

        return mb_stripos($haystack, $pattern) !== false;
    }

    public function isRegex(string $pattern): bool
    {
        return str_starts_with($pattern, self::REGEX_PREFIX);
    }

    private function matchesRegex(string $body, string $haystack, string $original): bool
    {
        $delimited = '#'.str_replace('#', '\#', $body).'#i';

        if (! $this->isUsableRegex($body, $delimited, $original)) {
            return false;
        }

        $result = $this->matchWithinBudget($delimited, $haystack);
        if ($result === false) {
            // Compiled clean against an empty subject in the guard, yet failed
            // on this haystack -- the backtrack budget tripped on a pathological
            // pattern. Treat as a non-match rather than surface the error, and
            // record it so a pathological corpus entry stays visible in the logs.
            $this->logger->warning('CorpusPatternMatcher: regex match failed, treated as non-match.', [
                'pattern' => $original,
            ]);

            return false;
        }

        return $result === 1;
    }

    // Runs the match under a lowered pcre.backtrack_limit so a pathological
    // corpus pattern aborts (preg_match returns false) instead of burning CPU,
    // then restores the previous limit — the bound holds whatever php.ini says.
    private function matchWithinBudget(string $delimited, string $haystack): int|false
    {
        $previousLimit = ini_set('pcre.backtrack_limit', (string) self::PCRE_BACKTRACK_BUDGET);

        try {
            return @preg_match($delimited, $haystack);
        } finally {
            if ($previousLimit !== false) {
                ini_set('pcre.backtrack_limit', $previousLimit);
            }
        }
    }

    // A corpus regex is only run against transaction text once its body is
    // non-empty, within MAX_REGEX_BODY_LENGTH, and compiles. The compile check
    // (empty subject) rejects a malformed pattern; a valid-but-pathological one
    // still runs, bounded by the backtrack budget in matchWithinBudget().
    private function isUsableRegex(string $body, string $delimited, string $original): bool
    {
        if ($body === '') {
            return false;
        }

        $length = mb_strlen($body);
        if ($length > self::MAX_REGEX_BODY_LENGTH) {
            $this->logger->warning('CorpusPatternMatcher: regex pattern exceeds the length cap, treated as non-match.', [
                'pattern' => $original,
                'length' => $length,
            ]);

            return false;
        }

        if (@preg_match($delimited, '') === false) {
            $this->logger->warning('CorpusPatternMatcher: invalid regex pattern, treated as non-match.', [
                'pattern' => $original,
            ]);

            return false;
        }

        return true;
    }
}
