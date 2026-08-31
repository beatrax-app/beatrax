<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Audit;

use Modules\DevMode\Internal\Services\OAuthScrubSet;

final readonly class RedactionExcerptCap
{
    public const int DEFAULT_MAX_BYTES = 8192;

    private const string BEARER_PATTERN = '/Authorization:\s*Bearer\s+\S+/i';

    private const string JWT_PATTERN = '/eyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}/';

    // beatrax:regenerate-recovery-codes prints ten live single-use credentials
    // to stdout, and this excerpt is what the audit row stores. The shape is
    // RecoveryCodeGenerator's exactly: five groups of four, drawn from an
    // alphabet with no I, L, O, 0 or 1.
    private const string RECOVERY_CODE_PATTERN = '/(?<![A-Z0-9-])[A-HJKMNP-Z2-9]{4}(?:-[A-HJKMNP-Z2-9]{4}){4}(?![A-Z0-9-])/';

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
        $scrubbed = (string) preg_replace(self::RECOVERY_CODE_PATTERN, '[REDACTED]', $scrubbed);

        if (strlen($scrubbed) <= $maxBytes) {
            return $scrubbed;
        }

        // A byte cap, not mb_substr: the column's limit is in bytes, so a
        // trailing partial glyph beats an unpredictable byte length.
        return substr($scrubbed, 0, $maxBytes);
    }
}
