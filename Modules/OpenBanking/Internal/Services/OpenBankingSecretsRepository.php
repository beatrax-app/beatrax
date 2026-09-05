<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Services;

use Carbon\CarbonImmutable;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\SafeDate;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Internal\Exceptions\OpenBankingCredentialsException;

/**
 * @link ../../../../.docs/features/open-banking/secrets-at-rest.md
 */
class OpenBankingSecretsRepository
{
    // Relative to the storage/app root that UserDataPathService::appPath()
    // resolves (respecting NATIVEPHP_STORAGE_PATH) — no leading `app/`. One
    // file per reader: there is no path here that names no user.
    private const string DIRECTORY_RELATIVE = 'secrets/open-banking';

    // The pre-keying store, global to the installation. Read once by the
    // migration that adopts it into a reader's own file, and never again.
    private const string LEGACY_PATH_RELATIVE = 'secrets/open-banking.json';

    private const string CONNECTIONS_KEY = 'connections';

    public function __construct(private readonly OpenBankingSecretsFile $file) {}

    public function hasApplication(int $userId): bool
    {
        $data = $this->readAll($userId);

        return self::stringOrNull($data['application_id'] ?? null) !== null
            && self::stringOrNull($data['private_key_pem'] ?? null) !== null;
    }

    public function saveApplication(int $userId, string $applicationId, string $privateKeyPem): void
    {
        $data = $this->readAll($userId);
        $data['application_id'] = $applicationId;
        $data['private_key_pem'] = $privateKeyPem;

        $this->file->write($this->pathFor($userId), $data);
    }

    // Written before the reader ever reaches the bank, so it must not disturb
    // a session this institution already holds: a consent that is abandoned at
    // the bank leaves the previous one fetchable.
    public function rememberScaHost(int $userId, string $institutionId, string $bankScaHost): void
    {
        $this->mergeConnection($userId, $institutionId, ['bank_sca_host' => $bankScaHost]);
    }

    public function rememberSession(
        int $userId,
        string $institutionId,
        string $sessionId,
        CarbonImmutable $consentExpiresAt,
    ): void {
        $this->mergeConnection($userId, $institutionId, [
            'session_id' => $sessionId,
            'consent_expires_at' => $consentExpiresAt->toAtomString(),
        ]);
    }

    // Gated on the private key alone, unlike hasApplication(): the wizard writes
    // the key first and must load() the half-written file to merge in the id.
    // A null institution asks for the application half by itself, which is the
    // only state the wizard has between step 1 and step 3.
    public function load(int $userId, ?string $institutionId = null): ?OpenBankingCredentials
    {
        $data = $this->readAll($userId);

        $privateKeyPem = self::stringOrNull($data['private_key_pem'] ?? null);
        if ($privateKeyPem === null) {
            return null;
        }

        $connection = $institutionId === null
            ? []
            : self::connectionsIn($data)[$institutionId] ?? [];

        return new OpenBankingCredentials(
            applicationId: self::stringOrNull($data['application_id'] ?? null) ?? '',
            privateKeyPem: $privateKeyPem,
            sessionId: self::stringOrNull($connection['session_id'] ?? null),
            consentExpiresAt: self::toDateTime($connection['consent_expires_at'] ?? null),
            bankScaHost: self::stringOrNull($connection['bank_sca_host'] ?? null),
            institutionId: $institutionId,
        );
    }

    // Fabricating OpenBankingCredentials only here is what lets the arch guard
    // assert a single credential source for the module.
    public function loadOrThrow(int $userId, string $institutionId): OpenBankingCredentials
    {
        $credentials = $this->load($userId, $institutionId);
        if ($credentials === null) {
            throw OpenBankingCredentialsException::notConfigured();
        }

        if ($credentials->sessionId === null) {
            throw OpenBankingCredentialsException::bankNotLinked($institutionId);
        }

        return $credentials;
    }

    /**
     * @return list<string>
     */
    public function connectedInstitutions(int $userId): array
    {
        $out = [];
        foreach (self::connectionsIn($this->readAll($userId)) as $institutionId => $connection) {
            if (self::stringOrNull($connection['session_id'] ?? null) !== null) {
                $out[] = $institutionId;
            }
        }

        return $out;
    }

    public function clear(int $userId): void
    {
        $this->file->delete($this->pathFor($userId));
    }

    // The institution the pre-keying file's one live session belonged to. The
    // migration needs it before it can name an owner, because the row carrying
    // that institution is what says whose session this was.
    public function legacyInstitutionId(): ?string
    {
        return self::stringOrNull($this->file->read($this->legacyPath())['institution_id'] ?? null);
    }

    // Moves the pre-keying file into $userId's own, session and all, so an
    // installed reader crosses the upgrade still connected. The legacy file is
    // removed only once the keyed one is on disk.
    public function adoptLegacyFile(int $userId): bool
    {
        $legacy = $this->file->read($this->legacyPath());
        $privateKeyPem = self::stringOrNull($legacy['private_key_pem'] ?? null);
        if ($privateKeyPem === null) {
            return false;
        }

        $this->file->write($this->pathFor($userId), self::keyedFromLegacy($legacy, $privateKeyPem));
        $this->file->delete($this->legacyPath());

        return true;
    }

    /**
     * @param  array<string, mixed>  $legacy
     * @return array<string, mixed>
     */
    private static function keyedFromLegacy(array $legacy, string $privateKeyPem): array
    {
        $institutionId = self::stringOrNull($legacy['institution_id'] ?? null);

        return [
            'application_id' => self::stringOrNull($legacy['application_id'] ?? null) ?? '',
            'private_key_pem' => $privateKeyPem,
            self::CONNECTIONS_KEY => $institutionId === null ? [] : [
                $institutionId => [
                    'session_id' => self::stringOrNull($legacy['session_id'] ?? null),
                    'consent_expires_at' => self::stringOrNull($legacy['consent_expires_at'] ?? null),
                    'bank_sca_host' => self::stringOrNull($legacy['bank_sca_host'] ?? null),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, ?string>  $changes
     */
    private function mergeConnection(int $userId, string $institutionId, array $changes): void
    {
        $data = $this->readAll($userId);
        $connections = self::connectionsIn($data);
        $connections[$institutionId] = array_merge($connections[$institutionId] ?? [], $changes);
        $data[self::CONNECTIONS_KEY] = $connections;

        $this->file->write($this->pathFor($userId), $data);
    }

    /**
     * @return array<string, mixed>
     */
    private function readAll(int $userId): array
    {
        return $this->file->read($this->pathFor($userId));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array<string, mixed>>
     */
    private static function connectionsIn(array $data): array
    {
        $raw = $data[self::CONNECTIONS_KEY] ?? null;
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $institutionId => $connection) {
            if (! is_array($connection)) {
                continue;
            }
            $fields = [];
            foreach ($connection as $field => $value) {
                $fields[(string) $field] = $value;
            }
            $out[(string) $institutionId] = $fields;
        }

        return $out;
    }

    private function pathFor(int $userId): string
    {
        return UserDataPathService::appPath(self::DIRECTORY_RELATIVE.'/'.$userId.'.json');
    }

    private function legacyPath(): string
    {
        return UserDataPathService::appPath(self::LEGACY_PATH_RELATIVE);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function toDateTime(mixed $value): ?CarbonImmutable
    {
        return is_string($value) ? SafeDate::parseOrNull($value) : null;
    }
}
