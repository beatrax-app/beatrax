<?php

declare(strict_types=1);

namespace Modules\Community\Public\Services;

use Psr\Log\LoggerInterface;

final class CorpusPatternMatcher
{
    public const REGEX_PREFIX = 'regex:';

    // Real corpus patterns are short merchant tokens. This does NOT bound
    // backtracking — a short `(a+)+$` is still pathological — so ReDoS safety
    // comes from matchWithinBudget(), not from this cap.
    public const MAX_REGEX_BODY_LENGTH = 256;

    // Backtracking on a corpus-supplied pattern is bounded here rather than by
    // php.ini's 1M default: a match past this many steps aborts and counts as
    // a non-match, still far above any real merchant token's needs.
    private const PCRE_BACKTRACK_BUDGET = 100_000;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function matches(string $pattern, string $haystack): bool
    {
        if ($pattern === '' || $haystack === '') {
            return false;
        }

        if (str_starts_with($pattern, self::REGEX_PREFIX)) {
            return $this->matchesRegex(substr($pattern, strlen(self::REGEX_PREFIX)), $haystack, $pattern);
        }

        return self::containsToken($haystack, $pattern);
    }

    // Whole token, not any substring: merchant tokens are short, so an
    // unanchored search matched OBI inside "mobiel" and RDW inside "Nordwind".
    // Edges are tested rather than \b, which means the opposite of what is
    // wanted beside a pattern that begins or ends with punctuation.
    public static function containsToken(string $haystack, string $needle): bool
    {
        if ($needle === '' || $haystack === '') {
            return false;
        }

        $delimited = '#(?<![\p{L}\p{N}])'.preg_quote($needle, '#').'(?![\p{L}\p{N}])#iu';

        return preg_match($delimited, $haystack) === 1;
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
            // Compiled clean in the guard yet failed here: the backtrack
            // budget tripped. Logged so a pathological corpus entry stays
            // visible rather than silently never matching.
            $this->logger->warning('CorpusPatternMatcher: regex match failed, treated as non-match.', [
                'pattern' => $original,
            ]);

            return false;
        }

        return $result === 1;
    }

    // Lowers pcre.backtrack_limit for the duration and restores it after, so
    // the bound holds whatever php.ini says.
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

    // The compile check rejects a malformed pattern; a valid-but-pathological
    // one still runs, bounded by matchWithinBudget().
    private function isUsableRegex(string $body, string $delimited, string $original): bool
    {
        return $body !== ''
            && $this->isWithinLengthCap($body, $original)
            && $this->compiles($delimited, $original);
    }

    private function isWithinLengthCap(string $body, string $original): bool
    {
        $length = mb_strlen($body);
        if ($length > self::MAX_REGEX_BODY_LENGTH) {
            $this->logger->warning('CorpusPatternMatcher: regex pattern exceeds the length cap, treated as non-match.', [
                'pattern' => $original,
                'length' => $length,
            ]);

            return false;
        }

        return true;
    }

    private function compiles(string $delimited, string $original): bool
    {
        if (@preg_match($delimited, '') === false) {
            $this->logger->warning('CorpusPatternMatcher: invalid regex pattern, treated as non-match.', [
                'pattern' => $original,
            ]);

            return false;
        }

        return true;
    }
}
