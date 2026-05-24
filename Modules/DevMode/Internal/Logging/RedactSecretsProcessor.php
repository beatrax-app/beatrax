<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * On-write Monolog processor that scrubs Bearer header tokens + JWT
 * shapes from every log line BEFORE the formatter writes it to disk.
 *
 * Registered via {@see PushRedactProcessor} (a Laravel tap class)
 * onto every handler of the `stack`, `single`, and `daily` channels
 * defined in `config/logging.php`. Together these three artifacts close
 * the on-write redaction-gap window in Wave 4 (W-1 fix), in the same
 * wave the runner UI lands — so no log line written from any new
 * surface (16-04b's audit pipeline, 16-04's SSE controller, the rest
 * of the app) hits storage with a Bearer/JWT token in cleartext.
 *
 * SEPARATE artifact from `Modules/DevMode/Internal/Audit/RedactionExcerptCap.php`,
 * which scrubs the audit-DB row excerpts. Both apply the same Bearer +
 * JWT regex baseline; both upgrade in 16-05 to consume the full
 * OAuthScrubSet.
 *
 * Baseline behavior (THIS plan, 16-04b — W-1 fix):
 *   - Replaces `Authorization: Bearer <token>` → `Authorization: Bearer [REDACTED]`
 *   - Replaces JWT-shape tokens (`eyJ…header.payload.signature`) → `[JWT_REDACTED]`
 *   - Recursively scrubs every string value inside `$record->context`
 *     and `$record->extra` (handlers that serialize the whole record
 *     into JSON include those branches).
 *   - LogRecord is immutable in Monolog v3; the processor returns
 *     `$record->with(message: …, context: …, extra: …)`.
 *
 * 16-05 upgrade contract (DO NOT BREAK):
 *   - 16-05 adds `OAuthScrubSet $scrubSet` to the constructor of THIS
 *     class and applies the compiled scrub-set regex BEFORE the
 *     Bearer/JWT scrub inside `__invoke()`.
 *   - The FQCN
 *     (`Modules\DevMode\Internal\Logging\RedactSecretsProcessor`)
 *     MUST remain stable across the upgrade.
 *   - The `__invoke(LogRecord $record): LogRecord` signature MUST
 *     remain stable.
 *   - The `config/logging.php` tap-slot registration
 *     (`PushRedactProcessor::class`) MUST keep working without edits —
 *     `PushRedactProcessor` resolves THIS class from the container
 *     so the constructor-DI change is invisible to it.
 *   - The baseline behavior (Bearer + JWT scrub on message + recursive
 *     context + extra) MUST still work after 16-05's upgrade — the
 *     baseline regression test
 *     `Modules/DevMode/tests/Unit/RedactSecretsProcessorBaselineTest.php`
 *     stays green through the upgrade.
 */
final class RedactSecretsProcessor implements ProcessorInterface
{
    /** Bearer-header scrub (case-insensitive). */
    private const BEARER_PATTERN = '/Authorization:\s*Bearer\s+\S+/i';

    /** JWT shape (eyJ...header.payload.signature with min 20-char segments). */
    private const JWT_PATTERN = '/eyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}/';

    public function __invoke(LogRecord $record): LogRecord
    {
        $message = $this->scrub($record->message);
        $context = $this->scrubArray($record->context);
        $extra = $this->scrubArray($record->extra);

        return $record->with(
            message: $message,
            context: $context,
            extra: $extra,
        );
    }

    /**
     * Recursively scrub every string value inside the array; arrays
     * recurse, non-strings pass through untouched.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function scrubArray(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $out[$key] = $this->scrubArray($value);
            } elseif (is_string($value)) {
                $out[$key] = $this->scrub($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Apply the baseline Bearer + JWT scrub in the documented order.
     */
    private function scrub(string $text): string
    {
        $text = (string) preg_replace(self::BEARER_PATTERN, 'Authorization: Bearer [REDACTED]', $text);
        $text = (string) preg_replace(self::JWT_PATTERN, '[JWT_REDACTED]', $text);

        return $text;
    }
}
