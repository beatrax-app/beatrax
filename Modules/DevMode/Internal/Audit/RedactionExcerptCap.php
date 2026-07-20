<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Audit;

use Modules\DevMode\Internal\Logging\RedactSecretsProcessor;
use Modules\DevMode\Internal\Services\OAuthScrubSet;

/**
 * Excerpt-capping + three-layer redaction wrapper used by
 * SpatieAuditWriter when it writes `stdout_excerpt` / `error_excerpt`
 * into the `dev_mode_audit` table.
 *
 * This is the audit-log excerpt layer (a separate artifact from
 * {@see RedactSecretsProcessor},
 * which handles the on-write Monolog scrub for storage/logs/*.log).
 * Both apply the same three-layer scrub (OAuth scrub-set → Bearer
 * header → JWT shape); they live at different paths because they
 * bound different exit points.
 *
 * Behavior:
 *   1. OAuth scrub-set (the `client_secret` + every string in
 *      `tokens_blob` for every `oauth_secrets` row) runs FIRST so a
 *      JWT-shaped real token reads as `[REDACTED]` rather than the
 *      less-specific `[JWT_REDACTED]`. The set's compiled regex
 *      runs in a single preg_replace pass regardless of set size.
 *   2. `Authorization: Bearer <token>` → `Authorization: Bearer [REDACTED]`.
 *   3. JWT shape → `[JWT_REDACTED]`.
 *   4. Byte-cap to $maxBytes (default 8 KiB).
 *
 * The cap runs LAST so a token straddling the byte boundary cannot
 * leak through as a partial.
 *
 * OAuthScrubSet is nullable so direct instantiation (e.g. in unit
 * tests that exercise only the Bearer + JWT branches) keeps working
 * without re-instantiating an empty scrub set; the container-bound
 * instance always passes the real singleton.
 */
final readonly class RedactionExcerptCap
{
    /** Default cap on the audit-row excerpt — 8 KiB per column. */
    public const DEFAULT_MAX_BYTES = 8192;

    /** Bearer-header scrub (case-insensitive). */
    private const BEARER_PATTERN = '/Authorization:\s*Bearer\s+\S+/i';

    /** JWT shape (eyJ...header.payload.signature with min 20-char segments). */
    private const JWT_PATTERN = '/eyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}/';

    public function __construct(
        private ?OAuthScrubSet $scrubSet = null,
    ) {}

    /**
     * Redact OAuth scrub-set + Bearer + JWT tokens from $text, then
     * cap to $maxBytes.
     */
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
