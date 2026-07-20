<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Services;

use Modules\Core\Models\SystemAlert;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\DevMode\Internal\Audit\RedactionExcerptCap;
use Modules\DevMode\Internal\Listeners\BustOAuthScrubSetOnSecretChange;
use Modules\DevMode\Internal\Logging\RedactSecretsProcessor;
use Modules\EmailScan\Models\OAuthSecret;
use Throwable;

/**
 * Singleton cache of every decrypted OAuth-secret string the running
 * app knows about. Both the on-write Monolog scrub
 * ({@see RedactSecretsProcessor}) and the on-write audit-row scrub
 * ({@see RedactionExcerptCap}) consume this set so a log line or
 * audit excerpt containing the literal value of an
 * oauth_secrets.client_secret or any string inside the tokens_blob
 * JSON is replaced with `[REDACTED]` before the bytes leave the
 * trust boundary.
 *
 * Threat model: a 3rd-party OAuth refresh that prints the raw token
 * into a log line (HTTP debug, exception message, dump) MUST NOT
 * persist that token to storage/logs/*.log or to the audit table.
 * The Monolog tap + the audit-row cap inject this set into their
 * pre-compiled scrub regex; on every log record / audit row the set
 * is applied in a single preg_replace sweep (a naive foreach +
 * str_replace would be O(n*m) per record; the compiled regex is one
 * pass per record).
 *
 * Cache lifecycle:
 *   - Lazy load on first all() / compiledPattern() call (avoids
 *     hitting the DB during framework boot before the schema is
 *     migrated, and dodges the early-boot encrypter circular dep).
 *   - {@see bust()} clears the cache. The Eloquent observer
 *     {@see BustOAuthScrubSetOnSecretChange} calls bust() on every
 *     OAuthSecret::saved and OAuthSecret::deleted event so a rotated
 *     secret takes effect on the very next log line.
 *
 * Cross-user scope: this set is NOT user-scoped. A multi-user install
 * holds one oauth_secrets row per (user_id, provider) pair, and we
 * read every row. The redaction surface is the host filesystem and
 * the audit DB row — both shared across users on the same machine —
 * so scrubbing every user's secret from every line is the only safe
 * shape.
 *
 * Acceptable rotation behavior (documented): once an OAuthSecret row
 * is DELETED the cache busts and the deleted string disappears from
 * the set. A subsequent log line containing the old (revoked) string
 * is NOT scrubbed — intentional: a revoked + removed token is no
 * longer sensitive to this app's threat model.
 */
class OAuthScrubSet
{
    /** @var list<string>|null */
    protected ?array $set = null;

    protected ?string $compiled = null;

    /**
     * Tracks whether the next load() is happening during the framework
     * boot phase (where a missing oauth_secrets table or an unbooted
     * encrypter is expected and must not halt the app) or at runtime
     * (where the same failure means redaction is silently disabled and
     * the operator needs to know).
     *
     * Starts true; the first successful load() flips it to false so
     * subsequent load() failures route through the runtime branch.
     */
    protected bool $bootPhase = true;

    /**
     * @param  SecretShield  $shield  Reveals the keychain-shielded
     *                                client_secret / tokens_blob on the desktop bundle so the scrub set
     *                                holds the real PLAINTEXT secrets (identity elsewhere). Without this
     *                                the set would collect safeStorage ciphertext and the true secret —
     *                                the value that actually appears in HTTP calls and could leak into
     *                                logs — would never be redacted.
     */
    public function __construct(
        private readonly SecretShield $shield,
    ) {}

    /**
     * Empty the cache. The next `all()` / `compiledPattern()` call
     * lazy-rebuilds from the live `oauth_secrets` table.
     */
    public function bust(): void
    {
        $this->set = null;
        $this->compiled = null;
    }

    /**
     * Every distinct decrypted secret string currently stored in the
     * `oauth_secrets` table — `client_secret` plus every leaf string
     * value inside each `tokens_blob` JSON object. Whitespace-only
     * or empty values are skipped (replacing the empty string would
     * corrupt every log record).
     *
     * @return list<string>
     */
    public function all(): array
    {
        if ($this->set !== null) {
            return $this->set;
        }

        $this->set = $this->load();

        return $this->set;
    }

    /**
     * Pre-compiled regex pattern matching every secret in `all()`.
     * Returns null when the set is empty so callers can skip the
     * `preg_replace` call entirely (single-pass O(n) vs O(n*m)).
     *
     * Pattern shape: `'/(s1|s2|s3)/'` with each `s` `preg_quote`'d.
     * Single pass over the input regardless of set size.
     */
    public function compiledPattern(): ?string
    {
        if ($this->compiled !== null) {
            return $this->compiled === '' ? null : $this->compiled;
        }

        $secrets = $this->all();
        if ($secrets === []) {
            $this->compiled = '';

            return null;
        }

        $alternation = implode('|', array_map(
            static fn (string $s): string => preg_quote($s, '/'),
            $secrets,
        ));

        $this->compiled = '/('.$alternation.')/';

        return $this->compiled;
    }

    /**
     * Read every OAuthSecret row's decrypted `client_secret` plus
     * every leaf string in its `tokens_blob` JSON. Reads bypass the
     * per-user `OAuthSecretsRepository` (which scopes to the current
     * user) because the scrub set is a system-wide redaction surface.
     *
     * Returns a de-duplicated list with empty / whitespace-only
     * strings filtered out.
     *
     * Boot vs runtime resilience:
     *   - During the framework boot phase, a missing oauth_secrets
     *     table or an unbooted encrypter is expected — return an
     *     empty set so app boot continues. The flag stays true and
     *     a future load() retries from scratch.
     *   - After the first successful load() the flag flips to false.
     *     A failure at THAT point means redaction is silently
     *     disabled across the Monolog tap AND audit-row pipeline —
     *     write a critical system_alerts row so the operator sees a
     *     banner on the next dashboard load. Best-effort: a
     *     system_alerts write that also fails (e.g. DB is fully
     *     down) is swallowed at the inner catch — the alternative
     *     is propagating the exception out of a log/audit codepath
     *     and crashing every request that emits a log line.
     *
     * @return list<string>
     */
    protected function load(): array
    {
        $collected = [];

        try {
            /** @var iterable<OAuthSecret> $rows */
            $rows = OAuthSecret::query()->get();
            foreach ($rows as $row) {
                // Reveal the keychain shield so the scrub set holds the true
                // plaintext secret, not desktop safeStorage ciphertext.
                $clientSecret = $this->shield->reveal($row->client_secret);
                if (trim($clientSecret) !== '') {
                    $collected[$clientSecret] = true;
                }

                $blob = $row->tokens_blob;
                if (is_string($blob) && $blob !== '') {
                    $decoded = json_decode($this->shield->reveal($blob), true);
                    if (is_array($decoded)) {
                        $this->collectStrings($decoded, $collected);
                    }
                }
            }
        } catch (Throwable $e) {
            if (! $this->bootPhase) {
                $this->recordRuntimeFailure($e);
            }

            return [];
        }

        // First successful load — leave the boot-phase window so
        // future failures route through the runtime alert branch.
        $this->bootPhase = false;

        return array_keys($collected);
    }

    /**
     * Write a critical SystemAlert when a post-boot OAuthScrubSet
     * load fails. Best-effort — a SystemAlert write that also fails
     * (e.g. the DB connection is fully down) is swallowed; the
     * alternative is propagating the exception out of a log/audit
     * codepath and crashing every request that emits a log line.
     */
    protected function recordRuntimeFailure(Throwable $e): void
    {
        try {
            SystemAlert::create([
                'user_id' => null,
                'kind' => 'oauth_scrub_set_failed',
                'severity' => 'critical',
                'message' => 'OAuth secret redaction is offline. Logs and audit excerpts may contain unredacted tokens until the next successful load.',
                'metadata' => [
                    'exception' => $e->getMessage(),
                    'exception_class' => get_class($e),
                ],
            ]);
        } catch (Throwable) {
            // Last-resort no-op — never break the surrounding logger.
        }
    }

    /**
     * Recursively walk a decoded `tokens_blob` array and add every
     * non-empty string leaf to `$collected` (keyed by value for
     * de-duplication).
     *
     * @param  array<array-key, mixed>  $values
     * @param  array<string, true>  $collected
     */
    private function collectStrings(array $values, array &$collected): void
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                $this->collectStrings($value, $collected);
            } elseif (is_string($value) && trim($value) !== '') {
                $collected[$value] = true;
            }
        }
    }
}
