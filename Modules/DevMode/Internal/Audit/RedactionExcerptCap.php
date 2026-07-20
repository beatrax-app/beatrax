<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Audit;

use Modules\DevMode\Internal\Services\OAuthScrubSet;

/**
 * @link ../../../../.docs/features/dev-mode/architecture.md
 */
final readonly class RedactionExcerptCap
{
    public const DEFAULT_MAX_BYTES = 8192;

    private const BEARER_PATTERN = '/Authorization:\s*Bearer\s+\S+/i';

    private const JWT_PATTERN = '/eyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}/';

    public function __construct(
        private ?OAuthScrubSet $scrubSet = null,
    ) {}

    // Redact OAuth scrub-set + Bearer + JWT tokens from $text, then cap
    // to $maxBytes (see the linked architecture doc for the fixed order).
    public function apply(string $text, int $maxBytes = self::DEFAULT_MAX_BYTES): string
    {
        $scrubbed = $text;

        if ($this->scrubSet !== null) {
            $pattern = $this->scrubSet->compiledPattern();
            if ($pattern !== null) {
                $replaced = preg_replace($pattern, '[REDACTED]', $scrubbed);
                if (is_string($replaced)) {
                    $scrubbed = $replaced;
                }
            }
        }

        $scrubbed = (string) preg_replace(self::BEARER_PATTERN, 'Authorization: Bearer [REDACTED]', $scrubbed);
        $scrubbed = (string) preg_replace(self::JWT_PATTERN, '[JWT_REDACTED]', $scrubbed);

        if (strlen($scrubbed) <= $maxBytes) {
            return $scrubbed;
        }

        // Byte-cap (not mb_substr) keeps the 8 KiB invariant exact on
        // the underlying TEXT column, tolerating a trailing partial
        // multi-byte glyph rather than the unpredictable byte sizes a
        // character-boundary substr would produce.
        return substr($scrubbed, 0, $maxBytes);
    }
}
