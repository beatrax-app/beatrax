<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Audit;

/**
 * Baseline excerpt-capping + Bearer + JWT redaction layer used by
 * SpatieAuditWriter when it writes `stdout_excerpt` / `error_excerpt`
 * into the `dev_mode_audit` table.
 *
 * This is the AUDIT-LOG excerpt layer (a SEPARATE artifact from
 * `Modules/DevMode/Internal/Logging/RedactSecretsProcessor.php`, which
 * handles the on-write Monolog scrub for `storage/logs/*.log`). Both
 * apply the same Bearer + JWT regex set in the baseline; both upgrade
 * in 16-05 to consume the full OAuthScrubSet via constructor DI.
 *
 * The two scrub paths exist because they cross different trust
 * boundaries: this one bounds what lands in the audit DB row; the
 * Monolog processor bounds what lands in the rolling log file.
 *
 * Baseline behavior (this plan, 16-04b):
 *   - Replaces `Authorization: Bearer <token>` → `Authorization: Bearer [REDACTED]`
 *   - Replaces JWT-shape tokens (`eyJ…header.payload.signature`) → `[JWT_REDACTED]`
 *   - Truncates the result to $maxBytes via mb_substr (UTF-8 safe).
 *
 * 16-05 upgrade contract: 16-05 adds `OAuthScrubSet $scrubSet` to the
 * constructor and applies the compiled scrub-set regex BEFORE the
 * Bearer/JWT scrub inside `apply()`. The FQCN, the `apply()` signature,
 * and SpatieAuditWriter's usage MUST remain stable across the upgrade.
 */
final readonly class RedactionExcerptCap
{
    /** Default cap per the D-24 audit-row shape — 8 KiB. */
    public const DEFAULT_MAX_BYTES = 8192;

    /** Bearer-header scrub (case-insensitive). */
    private const BEARER_PATTERN = '/Authorization:\s*Bearer\s+\S+/i';

    /** JWT shape (eyJ...header.payload.signature with min 20-char segments). */
    private const JWT_PATTERN = '/eyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}/';

    /**
     * Redact Bearer + JWT tokens from $text and cap to $maxBytes.
     *
     * The cap runs AFTER the redaction so a token that straddles the
     * boundary cannot leak through as a partial.
     */
    public function apply(string $text, int $maxBytes = self::DEFAULT_MAX_BYTES): string
    {
        $scrubbed = (string) preg_replace(self::BEARER_PATTERN, 'Authorization: Bearer [REDACTED]', $text);
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
