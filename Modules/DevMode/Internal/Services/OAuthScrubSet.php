<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\Core\Public\Enums\OAuthAlertKind;
use Modules\Core\Public\Enums\SystemAlertSeverity;
use Modules\Core\Public\Services\SystemAlertWriter;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\StoredCopy;
use Modules\EmailScan\Models\OAuthSecret;
use Throwable;

class OAuthScrubSet
{
    private const string NOTHING_TO_SCRUB = '';

    // The tokens_blob fields OAuthSecretsRepository writes that are secrets.
    // It writes `id`, `provider`, `email`, `scope` and `expires_at` beside
    // them; those are ordinary words, and collecting them turned every log
    // line that said "gmail" or carried a timestamp into [REDACTED].
    /** @var list<string> */
    private const array SECRET_BLOB_FIELDS = ['access_token', 'refresh_token'];

    /** @var list<string>|null */
    protected ?array $set = null;

    protected ?string $compiled = null;

    // During boot a missing table or unbooted encrypter is expected and must
    // not halt boot; the identical failure at runtime means redaction is off.
    protected bool $bootPhase = true;

    // compiledPattern() runs on every log record, so an unreachable table
    // would raise one alert per line written; the operator needs the fact
    // once, not a flood of it.
    protected bool $runtimeFailureReported = false;

    /** @var array<int, true> the oauth_secrets rows already reported as keyless */
    protected array $keylessRowsReported = [];

    // Without the shield the set would hold desktop safeStorage ciphertext,
    // and the plaintext that actually reaches the logs would go unredacted.
    public function __construct(
        private readonly SecretShield $shield,
        private readonly SystemAlertWriter $alerts,
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
        return $this->loadedSet() ?? [];
    }

    public function compiledPattern(): ?string
    {
        if ($this->compiled !== null) {
            return $this->compiled === self::NOTHING_TO_SCRUB ? null : $this->compiled;
        }

        $secrets = $this->loadedSet();

        // An empty set is memoised; a failed load is not. Memoising the failure
        // would keep redaction off for the rest of the process after whatever
        // caused it had cleared.
        if ($secrets === []) {
            $this->compiled = self::NOTHING_TO_SCRUB;
        }

        if ($secrets === null || $secrets === []) {
            return null;
        }

        $alternation = implode('|', array_map(
            static fn (string $s): string => preg_quote($s, '/'),
            $secrets,
        ));

        $this->compiled = '/('.$alternation.')/';

        return $this->compiled;
    }

    // Null means the load failed, which is not the same answer as an empty
    // set: the caller retries rather than caching "nothing to scrub".
    /**
     * @return list<string>|null
     */
    private function loadedSet(): ?array
    {
        if ($this->set !== null) {
            return $this->set;
        }

        $loaded = $this->load();
        if ($loaded === null) {
            return null;
        }

        $this->set = $loaded;

        return $loaded;
    }

    // Bypasses the per-user OAuthSecretsRepository because the scrub set is a
    // system-wide surface: every secret must be redacted for every reader.
    /**
     * @return list<string>|null
     */
    protected function load(): ?array
    {
        $collected = [];

        try {
            /** @var iterable<OAuthSecret> $rows */
            $rows = OAuthSecret::query()->get();
            foreach ($rows as $row) {
                $this->collectRow($row, $collected);
            }

            return array_keys($collected);
        } catch (Throwable $e) {
            if (! $this->bootPhase && ! $this->runtimeFailureReported) {
                $this->runtimeFailureReported = true;
                $this->recordRuntimeFailure($e);
            }

            return null;
        } finally {
            // Cleared on the failing path too: a load that failed the first
            // time still ends boot, so the second failure is a runtime one.
            $this->bootPhase = false;
        }
    }

    // A row encrypted under a superseded APP_KEY throws; reaching load()'s
    // catch emptied the WHOLE set, so one stale credential used to turn
    // redaction off everywhere instead of just for itself.
    /**
     * @param  array<string, true>  $collected
     */
    private function collectRow(OAuthSecret $row, array &$collected): void
    {
        // The two columns are encrypted independently, so one of them being
        // unreadable says nothing about the other. Under a single try, a stale
        // client_secret kept that account's LIVE access_token out of the set.
        $this->collectClientSecret($row, $collected);
        $this->collectTokensBlob($row, $collected);
    }

    /**
     * @param  array<string, true>  $collected
     */
    private function collectClientSecret(OAuthSecret $row, array &$collected): void
    {
        try {
            $clientSecret = $this->shield->reveal($row->client_secret);
        } catch (DecryptException $e) {
            $this->reportKeylessCredential($row, $e);

            return;
        }

        if (trim($clientSecret) !== '') {
            $collected[$clientSecret] = true;
        }
    }

    /**
     * @param  array<string, true>  $collected
     */
    private function collectTokensBlob(OAuthSecret $row, array &$collected): void
    {
        try {
            // Inside the try, not before it: the `encrypted` cast decrypts on
            // attribute access, so reading the column is where it throws.
            $blob = $row->tokens_blob;
            if (! is_string($blob) || $blob === '') {
                return;
            }

            $decoded = json_decode($this->shield->reveal($blob), true);
        } catch (DecryptException $e) {
            $this->reportKeylessCredential($row, $e);

            return;
        }

        if (is_array($decoded)) {
            $this->collectStrings($decoded, $collected);
        }
    }

    // Unreadable is unleakable only while it stays unreadable: the set is
    // memoised, so a key that comes back mid-process leaves this credential
    // usable — and unredacted — for the rest of it. Once per row, because
    // compiledPattern() runs on every log record and bust() reloads.
    private function reportKeylessCredential(OAuthSecret $row, DecryptException $e): void
    {
        if (isset($this->keylessRowsReported[$row->id])) {
            return;
        }

        $this->keylessRowsReported[$row->id] = true;

        // Not gated on bootPhase the way load()'s catch is: a missing table
        // during boot clears itself, whereas a row no key opens reads the same
        // on every pass — and the first pass is nearly always a boot one.
        $this->recordRuntimeFailure($e, ['provider' => $row->provider]);
    }

    /**
     * @param  array<string, string>  $about
     */
    protected function recordRuntimeFailure(Throwable $e, array $about = []): void
    {
        try {
            if ($this->alertAlreadyOpen()) {
                return;
            }

            $line = CopyLine::of('core::alerts.messages.oauth_scrub_set_failed');

            $this->alerts->raiseOnceSystemWide(
                kind: OAuthAlertKind::ScrubSetFailed->value,
                severity: SystemAlertSeverity::Critical->value,
                message: $line->sentence(),
                metadata: StoredCopy::inParams($line) + $about + [
                    'exception' => $e->getMessage(),
                    'exception_class' => get_class($e),
                ],
            );
        } catch (Throwable) {
            // Swallowed: this runs inside a logger call, so a failing alert
            // write would crash every request that emits a log line.
        }
    }

    // The in-process flags above cannot hold across requests, and a keyless
    // credential is a standing state: without this the alert is raised again by
    // every request that writes a log line, burying its own first report. Two
    // desktop processes booting together both read false, which the key refuses.
    private function alertAlreadyOpen(): bool
    {
        // Through the raw query builder, as every other existence probe here
        // is: SystemAlert::query()->exists() trips staticMethod.dynamicCall.
        return (new SystemAlert)->getConnection()
            ->table('system_alerts')
            ->where('kind', OAuthAlertKind::ScrubSetFailed->value)
            ->whereNull('acknowledged_at')
            ->exists();
    }

    // Recursive because the blob is a map of inbox id → entry, and older rows
    // hold one flat entry instead.
    /**
     * @param  array<array-key, mixed>  $values
     * @param  array<string, true>  $collected
     */
    private function collectStrings(array $values, array &$collected): void
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $this->collectStrings($value, $collected);

                continue;
            }

            if (! is_string($value) || trim($value) === '' || ! self::isSecretField($key)) {
                continue;
            }

            $collected[$value] = true;
        }
    }

    private static function isSecretField(int|string $key): bool
    {
        return is_string($key)
            && in_array(strtolower($key), self::SECRET_BLOB_FIELDS, true);
    }
}
