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
