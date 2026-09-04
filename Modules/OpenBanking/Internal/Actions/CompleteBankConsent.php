<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingHttpClient;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Internal\Exceptions\OpenBankingCallbackException;
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsRepository;
use Modules\OpenBanking\Internal\Services\SecretsWriteFailed;
use Modules\OpenBanking\Internal\Support\ConsentWindow;

/**
 * @link ../../../../.docs/features/open-banking/architecture.md#consent--oauth-dance
 */
final readonly class CompleteBankConsent
{
    public function __construct(
        private EnableBankingHttpClient $client,
        private OpenBankingSecretsRepository $secrets,
        private DatabaseManager $db,
        private Clock $clock,
    ) {}

    public function __invoke(int $userId, string $code): int
    {
        if ($code === '') {
            throw OpenBankingCallbackException::noAuthorizationCode();
        }

        $credentials = $this->secrets->load();
        if ($credentials === null || $credentials->institutionId === null) {
            throw OpenBankingCallbackException::wizardIncomplete();
        }

        $session = $this->client->createSession($code);

        $sessionId = $this->client->sessionIdFrom($session);
        if ($sessionId === null) {
            throw OpenBankingCallbackException::noSessionId();
        }

        // Not gated like sessionId: a missing accounts[] entry does not
        // invalidate a completed consent, and a later fetch reports it itself.
        $accountUid = $this->client->accountUidFrom($session);

        $institutionId = $credentials->institutionId;
        $now = $this->clock->now();
        $nowString = $now->toDateTimeString();
        $consentExpiresAt = ConsentWindow::expiresAfter($now);

        $upsert = $this->upsertConnectionRow($userId, $institutionId, $accountUid, $nowString, $consentExpiresAt->toDateTimeString());

        // The secrets write happens after the DB commit, so a failure here needs
        // a compensating rollback or the row points at no session material.
        try {
            $this->secrets->save(new OpenBankingCredentials(
                applicationId: $credentials->applicationId,
                privateKeyPem: $credentials->privateKeyPem,
                sessionId: $sessionId,
                consentExpiresAt: $consentExpiresAt,
                bankScaHost: $credentials->bankScaHost,
                institutionId: $institutionId,
            ));
        } catch (SecretsWriteFailed $e) {
            $this->rollbackConnectionRow($upsert, $userId, $nowString);

            throw $e;
        }

        return $upsert['id'];
    }

    /**
     * @return array{id: int, isNew: bool, priorConsentExpiresAt: ?string, priorConsentRevokedAt: ?string, priorAccountUid: ?string}
     */
    private function upsertConnectionRow(
        int $userId,
        string $institutionId,
        ?string $accountUid,
        string $nowString,
        string $consentExpiresAtString,
    ): array {
        $existingRow = $this->db->connection()->table('open_banking_connections')
            ->where('user_id', $userId)
            ->where('institution_id', $institutionId)
            ->first(['id', 'consent_expires_at', 'consent_revoked_at', 'account_uid']);
        $existingId = ($existingRow !== null && is_numeric($existingRow->id)) ? (int) $existingRow->id : null;

        // Snapshot the pre-update values so the re-link path can restore them if
        // the secrets write fails, rather than advertise a consent it cannot back.
        $priorConsentExpiresAt = ($existingRow !== null && is_string($existingRow->consent_expires_at))
            ? $existingRow->consent_expires_at
            : null;
        $priorConsentRevokedAt = ($existingRow !== null && is_string($existingRow->consent_revoked_at))
            ? $existingRow->consent_revoked_at
            : null;
        $priorAccountUid = ($existingRow !== null && is_string($existingRow->account_uid))
            ? $existingRow->account_uid
            : null;

        $connectionId = $this->db->connection()->transaction(function () use (
            $existingId, $userId, $institutionId, $accountUid, $nowString, $consentExpiresAtString,
        ): int {
            $connection = $this->db->connection();

            if ($existingId !== null) {
                $connection->table('open_banking_connections')
                    ->where('id', $existingId)
                    ->where('user_id', $userId)
                    ->update([
                        'consent_expires_at' => $consentExpiresAtString,
                        // A fresh consent is exactly what a revoked one needed:
                        // leaving the stamp would keep the tile reading Revoked
                        // over a session the bank has just re-granted.
                        'consent_revoked_at' => null,
                        // A re-link may surface a different account_uid, or the
                        // first one, so always take THIS session's.
                        'account_uid' => $accountUid,
                        'updated_at' => $nowString,
                    ]);

                return $existingId;
            }

            return $connection->table('open_banking_connections')->insertGetId([
                'user_id' => $userId,
                'institution_id' => $institutionId,
                'account_uid' => $accountUid,
                'bank_display_name' => null,
                'enabled' => false,
                'consent_expires_at' => $consentExpiresAtString,
                'consent_revoked_at' => null,
                'last_successful_sync_at' => null,
                'last_attempt_at' => null,
                'last_attempt_status' => null,
                'created_at' => $nowString,
                'updated_at' => $nowString,
            ]);
        });

        return [
            'id' => $connectionId,
            'isNew' => $existingId === null,
            'priorConsentExpiresAt' => $priorConsentExpiresAt,
            'priorConsentRevokedAt' => $priorConsentRevokedAt,
            'priorAccountUid' => $priorAccountUid,
        ];
    }

    /**
     * @param  array{id: int, isNew: bool, priorConsentExpiresAt: ?string, priorConsentRevokedAt: ?string, priorAccountUid: ?string}  $upsert
     */
    private function rollbackConnectionRow(array $upsert, int $userId, string $nowString): void
    {
        $this->db->connection()->transaction(function () use ($upsert, $userId, $nowString): void {
            $connection = $this->db->connection();

            if ($upsert['isNew']) {
                $connection->table('open_banking_connections')
                    ->where('id', $upsert['id'])
                    ->where('user_id', $userId)
                    ->delete();

                return;
            }

            $connection->table('open_banking_connections')
                ->where('id', $upsert['id'])
                ->where('user_id', $userId)
                ->update([
                    'consent_expires_at' => $upsert['priorConsentExpiresAt'],
                    'consent_revoked_at' => $upsert['priorConsentRevokedAt'],
                    'account_uid' => $upsert['priorAccountUid'],
                    'updated_at' => $nowString,
                ]);
        });
    }
}
