<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Http\Controllers;

use Illuminate\Database\DatabaseManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\OpenBanking\Internal\Adapters\EnableBanking\EnableBankingHttpClient;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Internal\Exceptions\OpenBankingCallbackException;
use Modules\OpenBanking\Internal\OAuth\InvalidStateException;
use Modules\OpenBanking\Internal\OAuth\OpenBankingStateRepository;
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsRepository;
use Modules\OpenBanking\Internal\Services\SecretsWriteFailed;
use Modules\OpenBanking\Internal\Support\ConsentWindow;
use RuntimeException;

final class OpenBankingCallbackController
{
    public function __construct(
        private readonly EnableBankingHttpClient $client,
        private readonly OpenBankingSecretsRepository $secrets,
        private readonly OpenBankingStateRepository $oauthState,
        private readonly CurrentUser $currentUser,
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly Redirector $redirector,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $cancellation = $this->cancellationMessage($request);
        if ($cancellation !== null) {
            return $this->redirector
                ->route('settings.open-banking')
                ->with('open_banking_canceled', $cancellation);
        }

        // Resolve the current user before consuming the state so the consume
        // call can verify the state's stored user_id matches.
        $userId = $this->currentUser->id();

        $stateParamRaw = $request->query('state');
        $stateParam = is_string($stateParamRaw) ? $stateParamRaw : '';
        if (! $this->oauthState->consumeState($stateParam, $userId)) {
            throw new InvalidStateException('Open Banking OAuth state mismatch.');
        }

        try {
            $connectionId = $this->completeConsent($request, $userId);
        } catch (RuntimeException $e) {
            // Every post-state refusal subclasses RuntimeException and carries
            // a user-facing reason, so one flash handles all of them.
            return $this->redirector
                ->route('settings.open-banking')
                ->with('open_banking_failed', $e->getMessage());
        }

        return $this->redirector
            ->route('settings.open-banking')
            ->with('open_banking_connected', $connectionId);
    }

    private function cancellationMessage(Request $request): ?string
    {
        $errorParam = $request->query('error');
        if (! is_string($errorParam) || $errorParam === '') {
            return null;
        }

        $description = $request->query('error_description');

        return is_string($description) && $description !== '' ? $description : $errorParam;
    }

    private function completeConsent(Request $request, int $userId): int
    {
        $codeRaw = $request->query('code');
        $code = is_string($codeRaw) ? $codeRaw : '';
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
        $consentExpiresAt = $now->addDays(ConsentWindow::VALID_FOR_DAYS);
        $consentExpiresAtString = $consentExpiresAt->toDateTimeString();

        $upsert = $this->upsertConnectionRow($userId, $institutionId, $accountUid, $nowString, $consentExpiresAtString);

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
     * @return array{id: int, isNew: bool, priorConsentExpiresAt: ?string, priorAccountUid: ?string}
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
            ->first(['id', 'consent_expires_at', 'account_uid']);
        $existingId = ($existingRow !== null && is_numeric($existingRow->id)) ? (int) $existingRow->id : null;

        // Snapshot the pre-update values so the re-link path can restore them if
        // the secrets write fails, rather than advertise a consent it cannot back.
        $priorConsentExpiresAt = ($existingRow !== null && is_string($existingRow->consent_expires_at))
            ? $existingRow->consent_expires_at
            : null;
        $priorAccountUid = ($existingRow !== null && is_string($existingRow->account_uid))
            ? $existingRow->account_uid
            : null;

        $connectionId = $this->db->connection()->transaction(function () use (
            $existingId, $userId, $institutionId, $accountUid, $nowString, $consentExpiresAtString,
        ): int {
            $connection = $this->db->connection();
            $connection->statement('PRAGMA busy_timeout = 5000');

            if ($existingId !== null) {
                $connection->table('open_banking_connections')
                    ->where('id', $existingId)
                    ->where('user_id', $userId)
                    ->update([
                        'consent_expires_at' => $consentExpiresAtString,
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
            'priorAccountUid' => $priorAccountUid,
        ];
    }

    /**
     * @param  array{id: int, isNew: bool, priorConsentExpiresAt: ?string, priorAccountUid: ?string}  $upsert
     */
    private function rollbackConnectionRow(array $upsert, int $userId, string $nowString): void
    {
        $this->db->connection()->transaction(function () use ($upsert, $userId, $nowString): void {
            $connection = $this->db->connection();
            $connection->statement('PRAGMA busy_timeout = 5000');

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
                    'account_uid' => $upsert['priorAccountUid'],
                    'updated_at' => $nowString,
                ]);
        });
    }
}
