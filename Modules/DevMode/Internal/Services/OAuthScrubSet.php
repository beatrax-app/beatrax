<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Services;

use Modules\Core\Models\SystemAlert;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\EmailScan\Models\OAuthSecret;
use Throwable;

/**
 * @link ../../../../.docs/features/dev-mode/architecture.md
 */
class OAuthScrubSet
{
    /** @var list<string>|null */
    protected ?array $set = null;

    protected ?string $compiled = null;

    // Tracks whether the next load() is happening during framework boot
    // (missing table / unbooted encrypter expected, must not halt boot)
    // or at runtime (same failure means redaction is silently disabled).
    // Starts true; the first successful load() flips it to false.
    protected bool $bootPhase = true;

    // Reveals the keychain-shielded client_secret/tokens_blob on the
    // desktop bundle so the scrub set holds the real plaintext secrets —
    // without this it would collect safeStorage ciphertext and the true
    // secret that leaks into logs would never be redacted.
    public function __construct(
        private readonly SecretShield $shield,
    ) {}

    public function bust(): void
    {
        $this->set = null;
        $this->compiled = null;
    }

    /**
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

    // Returns null when the set is empty so callers can skip the
    // preg_replace call entirely (single-pass O(n) vs O(n*m)). Pattern
    // shape: '/(s1|s2|s3)/' with each `s` preg_quote'd.
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

    // Reads bypass the per-user OAuthSecretsRepository since the scrub
    // set is a system-wide surface. During boot a missing table is
    // expected (empty set); after first success a failure instead
    // writes a critical system_alerts row (recordRuntimeFailure).
    /**
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

    // Best-effort: a SystemAlert write that also fails (e.g. DB fully
    // down) is swallowed — the alternative is crashing every request
    // that emits a log line.
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
            // Last-resort no-op: a SystemAlert write failure here must
            // never propagate and break the surrounding logger call.
        }
    }

    /**
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
