<?php

declare(strict_types=1);

namespace Modules\Community\Public\Services;

use Psr\Log\LoggerInterface;

final class CorpusPatternMatcher
{
    public const string REGEX_PREFIX = 'regex:';

    // Real corpus patterns are short merchant tokens. This does NOT bound
    // backtracking — a short `(a+)+$` is still pathological — so ReDoS safety
    // comes from matchWithinBudget(), not from this cap.
    public const int MAX_REGEX_BODY_LENGTH = 256;

    // Backtracking on a corpus-supplied pattern is bounded here rather than by
    // php.ini's 1M default: a match past this many steps aborts and counts as
    // a non-match, still far above any real merchant token's needs.
    private const int PCRE_BACKTRACK_BUDGET = 100_000;

    /** @var array<string, string|null> */
    private array $compiledRegexes = [];

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
    public static function containsToken(string $haystack, string $needle): bool
    {
        $compiled = self::compileToken($needle);

        return $compiled !== null && self::matchesCompiled($compiled, $haystack);
    }

    // Every decision here reads the needle and nothing else, so a scan holds the
    // result for the whole corpus rather than repeating it per description. Null
    // is a needle that can never match any haystack, which is why a caller may
    // drop the row instead of carrying it into the scan.
    public static function compileToken(string $needle): ?string
    {
        // A preg_quote'd literal between two zero-width lookarounds cannot
        // exhaust the backtrack limit, so invalid UTF-8 is the only way the /u
        // match can fail — and a byte string that is not text holds no token
        // to find.
        if ($needle === '' || ! mb_check_encoding($needle, 'UTF-8')) {
            return null;
        }

        // Both edges are asserted only where the needle's own edge is a word
        // character, so a needle made only of punctuation asserts neither and
        // decays to a bare substring search: the pattern `-` would rename
        // every transaction carrying a hyphen.
        if (preg_match('/[\p{L}\p{N}]/u', $needle) !== 1) {
            return null;
        }

        // Each edge is asserted only where the needle's OWN edge is alphanumeric.
        // A pattern ending in punctuation carries its own boundary — asserting
        // past it made `AMAZON.` stop matching `AMAZON.NL`.
        $before = self::isWordEdge(mb_substr($needle, 0, 1)) ? '(?<![\p{L}\p{N}])' : '';
        $after = self::isWordEdge(mb_substr($needle, -1)) ? '(?![\p{L}\p{N}])' : '';

        return '#'.$before.preg_quote($needle, '#').$after.'#iu';
    }

    // The haystack half of containsToken, and the only half a scan repeats.
    // `$compiled` is what compileToken() returned, which is what leaves invalid
    // UTF-8 in the haystack as the single remaining way the /u match can fail.
    public static function matchesCompiled(string $compiled, string $haystack): bool
    {
        if ($haystack === '' || ! mb_check_encoding($haystack, 'UTF-8')) {
            return false;
        }

        return preg_match($compiled, $haystack) === 1;
    }

    // \p{M} counts: an NFD needle ends in a combining mark, and reading that
    // as punctuation asserted no trailing boundary at all, so `café` decomposed
    // matched inside `caféteria`.
    private static function isWordEdge(string $character): bool
    {
        return preg_match('/^[\p{L}\p{N}\p{M}]$/u', $character) === 1;
    }

    public function isRegex(string $pattern): bool
    {
        return str_starts_with($pattern, self::REGEX_PREFIX);
    }

    private function matchesRegex(string $body, string $haystack, string $original): bool
    {
        $delimited = $this->compiledRegex($body, $original);

        if ($delimited === null) {
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

    // The length cap and the compile probe read the pattern alone, while a
    // corpus scan runs it against every description. Keyed by the pattern — a
    // closed set, the `regex:` rows of the bundled corpus — so each row is
    // judged, and any warning it earns logged, once per instance.
    private function compiledRegex(string $body, string $original): ?string
    {
        if (array_key_exists($original, $this->compiledRegexes)) {
            return $this->compiledRegexes[$original];
        }

        $delimited = '#'.str_replace('#', '\#', $body).'#i';

        return $this->compiledRegexes[$original] = $this->isUsableRegex($body, $delimited, $original)
            ? $delimited
            : null;
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
