<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Logging;

use Modules\DevMode\Internal\Services\OAuthScrubSet;
use Modules\DevMode\Providers\DevModeServiceProvider;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * On-write Monolog processor that scrubs every OAuth secret literal
 * AND `Authorization: Bearer …` header AND JWT-shape token from every
 * log line BEFORE the formatter writes it to disk (CONTEXT D-29 — on
 * write).
 *
 * Registered via {@see PushRedactProcessor} (a Laravel tap class) onto
 * every handler of the `stack`, `single`, and `daily` channels defined
 * in `config/logging.php`. Together these artifacts enforce the
 * belt+braces redaction discipline: every log record passes through
 * the same processor at write time, and 16-05's LogStreamController
 * re-applies the same processor at read time as defense in depth (D-29).
 *
 * The on-write redaction order matters:
 *   1. OAuth scrub-set (the `client_secret` + every string in
 *      `tokens_blob` for every `oauth_secrets` row) — runs FIRST so
 *      a literal that looks like a JWT (which our OAuth tokens often
 *      do, since refresh tokens encode user state as JWT) is replaced
 *      with `[REDACTED]` rather than the less-specific
 *      `[JWT_REDACTED]`. The scrub-set's compiled regex is a single
 *      pre-compiled alternation; one `preg_replace` per record
 *      regardless of set size (Pitfall 8 mitigation).
 *   2. `Authorization: Bearer …` header pattern.
 *   3. JWT shape (eyJ…header.payload.signature).
 *
 * SEPARATE artifact from `Modules/DevMode/Internal/Audit/RedactionExcerptCap.php`
 * (the audit-DB row scrub). Both apply the SAME three-layer redaction;
 * both live at different paths because they bound different exit
 * points: this one writes into the rolling log file, the other writes
 * into the `dev_mode_audit` row.
 *
 * Container resolution contract: {@see PushRedactProcessor::__invoke()}
 * resolves THIS class via the container (`Container::getInstance()->make(...)`)
 * on every channel boot. The container singleton uses the binding
 * declared in {@see DevModeServiceProvider::register()},
 * which constructor-injects the {@see OAuthScrubSet} singleton. The
 * config/logging.php tap-slot registration stays stable across the
 * 16-04b baseline → 16-05 upgrade.
 *
 * Empty-scrub-set fast path: when `OAuthScrubSet::compiledPattern()`
 * returns null (no `oauth_secrets` rows yet, or the table is missing,
 * or the encrypter rejected the cast), the scrub-set step is skipped.
 * Bearer + JWT scrubbing still runs.
 */
final class RedactSecretsProcessor implements ProcessorInterface
{
    /** Bearer-header scrub (case-insensitive). */
    private const BEARER_PATTERN = '/Authorization:\s*Bearer\s+\S+/i';

    /** JWT shape (eyJ...header.payload.signature with min 20-char segments). */
    private const JWT_PATTERN = '/eyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}/';

    /**
     * `OAuthScrubSet` is nullable so the baseline regression test
     * (which calls `new RedactSecretsProcessor` directly) keeps
     * working without re-instantiating an empty scrub set; the
     * container binding always passes the real singleton.
     */
    public function __construct(
        private readonly ?OAuthScrubSet $scrubSet = null,
    ) {}

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
     * Apply the three-layer scrub in the documented order: OAuth
     * scrub-set first (so JWT-shaped real tokens are recognised as
     * `[REDACTED]` rather than `[JWT_REDACTED]`), then Bearer header
     * pattern, then JWT shape.
     */
    public function scrub(string $text): string
    {
        if ($this->scrubSet !== null) {
            $pattern = $this->scrubSet->compiledPattern();
            if ($pattern !== null) {
                $replaced = preg_replace($pattern, '[REDACTED]', $text);
                if (is_string($replaced)) {
                    $text = $replaced;
                }
            }
        }

        $text = (string) preg_replace(self::BEARER_PATTERN, 'Authorization: Bearer [REDACTED]', $text);
        $text = (string) preg_replace(self::JWT_PATTERN, '[JWT_REDACTED]', $text);

        return $text;
    }
}
