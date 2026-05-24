<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Audit;

use Modules\DevMode\Internal\Services\OAuthScrubSet;

/**
 * Excerpt-capping + three-layer redaction wrapper used by SpatieAuditWriter
 * when it writes `stdout_excerpt` / `error_excerpt` into the
 * `dev_mode_audit` table.
 *
 * This is the AUDIT-LOG excerpt layer (a SEPARATE artifact from
 * `Modules/DevMode/Internal/Logging/RedactSecretsProcessor.php`, which
 * handles the on-write Monolog scrub for `storage/logs/*.log`). Both
 * apply the SAME three-layer scrub (OAuth scrub-set → Bearer header →
 * JWT shape); they live at different paths because they bound different
 * exit points.
 *
 * Behavior:
 *   1. OAuth scrub-set (the `client_secret` + every string in
 *      `tokens_blob` for every `oauth_secrets` row) — runs FIRST so a
 *      JWT-shaped real token reads as `[REDACTED]` rather than the
 *      less-specific `[JWT_REDACTED]`. Single-pass compiled regex
 *      per record (Pitfall 8 mitigation).
 *   2. `Authorization: Bearer <token>` → `Authorization: Bearer [REDACTED]`.
 *   3. JWT shape → `[JWT_REDACTED]`.
 *   4. Byte-cap to $maxBytes (default 8 KiB per D-24).
 *
 * The cap runs LAST so a token straddling the byte boundary cannot
 * leak through as a partial.
 *
 * `OAuthScrubSet` is nullable so callers that legitimately want only
 * Bearer + JWT scrubbing (none exist after this plan but the baseline
 * AuditLogWriteTest from 16-04b instantiates this class directly with
 * `new RedactionExcerptCap;`) continue to work without modification.
 * The container-bound instance always passes the real singleton.
 */
final readonly class RedactionExcerptCap
{
    /** Default cap per the D-24 audit-row shape — 8 KiB. */
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

        // Byte-cap (not mb_substr) so the 8 KiB invariant is exact
        // on the underlying TEXT column. The downstream consumer
        // tolerates a trailing partial multi-byte glyph; the
        // alternative (mb_substr at character boundaries) gives
        // unpredictable byte sizes that complicate the cap test.
        return substr($scrubbed, 0, $maxBytes);
    }
}
