<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\Core\Public\Enums\SystemAlertSeverity;
use Modules\EmailScan\Models\OAuthSecret;
use Throwable;

class OAuthScrubSet
{
    /** @var list<string>|null */
    protected ?array $set = null;

    protected ?string $compiled = null;

    // During boot a missing table or unbooted encrypter is expected and must
    // not halt boot; the identical failure at runtime means redaction is off.
    protected bool $bootPhase = true;

    // Without the shield the set would hold desktop safeStorage ciphertext,
    // and the plaintext that actually reaches the logs would go unredacted.
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

    // null means "nothing to scrub", so callers skip preg_replace entirely.
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

    // Bypasses the per-user OAuthSecretsRepository because the scrub set is a
    // system-wide surface: every secret must be redacted for every reader.
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
                $this->collectRow($row, $collected);
            }
        } catch (Throwable $e) {
            if (! $this->bootPhase) {
                $this->recordRuntimeFailure($e);
            }

            return [];
        }

        $this->bootPhase = false;

        return array_keys($collected);
    }

    // A row encrypted under a superseded APP_KEY throws; reaching load()'s
    // catch emptied the WHOLE set, so one stale credential used to turn
    // redaction off everywhere instead of just for itself.
    /**
     * @param  array<string, true>  $collected
     */
    private function collectRow(OAuthSecret $row, array &$collected): void
    {
        try {
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
        } catch (DecryptException) {
            // Unreadable is also unleakable: there is no plaintext here to
            // scrub, and the credential itself needs re-authenticating.
        }
    }

    protected function recordRuntimeFailure(Throwable $e): void
    {
        try {
            SystemAlert::create([
                'user_id' => null,
                'kind' => 'oauth_scrub_set_failed',
                'severity' => SystemAlertSeverity::Critical->value,
                'message' => 'OAuth secret redaction is offline. Logs and audit excerpts may contain unredacted tokens until the next successful load.',
                'metadata' => [
                    'exception' => $e->getMessage(),
                    'exception_class' => get_class($e),
                ],
            ]);
        } catch (Throwable) {
            // Swallowed: this runs inside a logger call, so a failing alert
            // write would crash every request that emits a log line.
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
