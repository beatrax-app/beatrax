<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Audit;

use Modules\DevMode\Internal\Services\OAuthScrubSet;

final readonly class RedactionExcerptCap
{
    public const DEFAULT_MAX_BYTES = 8192;

    private const BEARER_PATTERN = '/Authorization:\s*Bearer\s+\S+/i';

    private const JWT_PATTERN = '/eyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}/';

    public function __construct(
        private ?OAuthScrubSet $scrubSet = null,
    ) {}

    // Scrub-set first: a real token that is also JWT-shaped must come out as
    // [REDACTED], not [JWT_REDACTED]. Capping happens last, after redaction.
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

        // A byte cap, not mb_substr: the column's limit is in bytes, so a
        // trailing partial glyph beats an unpredictable byte length.
        return substr($scrubbed, 0, $maxBytes);
    }
}
