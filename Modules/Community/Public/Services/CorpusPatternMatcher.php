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

    // A corpus regex body longer than this is refused before it runs: real
    // classification patterns are short merchant tokens, so the cap only ever
    // rejects a hostile or accidental blob, bounding worst-case match cost.
    public const MAX_REGEX_BODY_LENGTH = 256;

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

        $result = @preg_match($delimited, $haystack);
        if ($result === false) {
            // Compiled against an empty subject in the guard, yet failed on
            // this haystack -- e.g. pcre.backtrack_limit tripped. Treat as a
            // non-match rather than surface the error, and record it so a
            // pathological corpus entry stays visible in the logs.
            $this->logger->warning('CorpusPatternMatcher: regex match failed, treated as non-match.', [
                'pattern' => $original,
            ]);

            return false;
        }

        return $result === 1;
    }

    // A corpus regex is only run against transaction text once its body is
    // non-empty, within MAX_REGEX_BODY_LENGTH, and compiles -- the compile
    // check uses an empty subject, which cannot backtrack, so a hostile or
    // malformed pattern is rejected before it can burn CPU on the haystack.
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
