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
use Modules\OpenBanking\Internal\OAuth\InvalidStateException;
use Modules\OpenBanking\Internal\OAuth\OpenBankingStateRepository;
use Modules\OpenBanking\Public\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Public\Services\OpenBankingSecretsRepository;
use Modules\OpenBanking\Public\Services\SecretsWriteFailed;
use RuntimeException;

/**
 * Invokable controller routed at GET /oauth/callback/open-banking —
 * handles the bank/aggregator redirect after SCA, mirroring
 * `Modules\EmailScan\Internal\Http\Controllers\OAuthCallbackController`'s
 * guard chain (provider-error -> CSRF-state -> no-code) and its
 * DB-then-secret compensating-rollback shape, adapted for Enable
 * Banking's two-step `/auth` -> `/sessions` exchange (there is no
 * refresh-token concept; the session is valid until the `access.
 * valid_until` requested at `/auth` time).
 *
 * Verifies the CSRF state AND the originating-user binding via
 * `OpenBankingStateRepository::consumeState()` before exchanging the
 * authorization `code` for a `session_id` via `EnableBankingHttpClient::
 * createSession()`. Persists `session_id` + consent expiry + the
 * previously-resolved bank SCA host to the secrets file, and non-secret
 * metadata (institution_id, account_uid, consent_expires_at,
 * last_successful_sync_at, last_attempt_at, enabled) to
 * `open_banking_connections` — `account_uid` is the FIRST entry of the
 * session response's `accounts[]` list (19-09 carried gap: nothing
 * persisted this before), resolved via `EnableBankingHttpClient::
 * accountUidFrom()` so `OpenBankingFetchService` has a value to thread
 * into `RemoteSourceAdapter::fetch()`. A secrets-write failure after a
 * NEW row insert compensating-rolls-back
 * that row (T-19-05-03); an existing-row update (re-link) surfaces a
 * flash instead of rewinding, mirroring OAuthCallbackController's
 * identical trade-off for its reconnect branch (the prior consent may
 * already be superseded by this attempt).
 */
final class OpenBankingCallbackController
{
    /**
     * Kept in sync with the identically-named constant on
     * `OpenBankingConnectController` (see that class's docblock).
     */
    private const CONSENT_VALID_FOR_DAYS = 180;

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
        $errorParam = $request->query('error');
        if (is_string($errorParam) && $errorParam !== '') {
            $description = $request->query('error_description');
            $message = is_string($description) && $description !== ''
                ? $description
                : $errorParam;

            return $this->redirector
                ->route('settings.open-banking')
                ->with('open_banking_canceled', $message);
        }

        // Resolve the current user BEFORE consuming the state so the
        // consume call can verify the state's stored user_id matches —
        // same ordering rationale as OAuthCallbackController.
        $userId = $this->currentUser->id();

        $stateParamRaw = $request->query('state');
        $stateParam = is_string($stateParamRaw) ? $stateParamRaw : '';
        if (! $this->oauthState->consumeState($stateParam, $userId)) {
            throw new InvalidStateException('Open Banking OAuth state mismatch.');
        }

        $codeRaw = $request->query('code');
        $code = is_string($codeRaw) ? $codeRaw : '';
        if ($code === '') {
            return $this->redirector
                ->route('settings.open-banking')
                ->with('open_banking_failed', 'Enable Banking callback returned no authorization code.');
        }

        $credentials = $this->secrets->load();
        if ($credentials === null || $credentials->institutionId === null) {
            return $this->redirector
                ->route('settings.open-banking')
                ->with('open_banking_failed', 'Finish the Open Banking setup wizard first.');
        }

        try {
            $session = $this->client->createSession($code);
        } catch (RuntimeException $e) {
            return $this->redirector
                ->route('settings.open-banking')
                ->with('open_banking_failed', $e->getMessage());
        }

        $sessionId = $this->client->sessionIdFrom($session);
        if ($sessionId === null) {
            return $this->redirector
                ->route('settings.open-banking')
                ->with('open_banking_failed', 'Enable Banking did not return a session id.');
        }

        // Not gated (unlike sessionId above): a missing accounts[] entry
        // does not invalidate the completed consent — it only means a
        // later fetch attempt (OpenBankingFetchService) has nothing to
        // sync against yet, which that service reports with its own
        // explicit error rather than blocking the callback here.
        $accountUid = $this->client->accountUidFrom($session);

        $institutionId = $credentials->institutionId;
        $now = $this->clock->now();
        $nowString = $now->toDateTimeString();
        $consentExpiresAt = $now->addDays(self::CONSENT_VALID_FOR_DAYS);
        $consentExpiresAtString = $consentExpiresAt->toDateTimeString();

        $existingIdRaw = $this->db->connection()->table('open_banking_connections')
            ->where('user_id', $userId)
            ->where('institution_id', $institutionId)
            ->value('id');
        $existingId = is_numeric($existingIdRaw) ? (int) $existingIdRaw : null;
        $isNew = $existingId === null;

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
                        // Re-link may surface a different account_uid than
                        // the original consent (or resolve one for the
                        // first time if it was null before) — always
                        // refresh it from THIS session's response.
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

        // The chmod-600 JSON write happens AFTER the DB commit for the
        // same reason OAuthCallbackController's does: a failure here
        // needs an explicit compensating rollback of the row we just
        // inserted, otherwise the user ends up with a connection row
        // pointing at no session material.
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
            if ($isNew) {
                $this->db->connection()->transaction(function () use ($connectionId, $userId): void {
                    $connection = $this->db->connection();
                    $connection->statement('PRAGMA busy_timeout = 5000');
                    $connection->table('open_banking_connections')
                        ->where('id', $connectionId)
                        ->where('user_id', $userId)
                        ->delete();
                });
            }

            return $this->redirector
                ->route('settings.open-banking')
                ->with('open_banking_failed', $e->getMessage());
        }

        return $this->redirector
            ->route('settings.open-banking')
            ->with('open_banking_connected', $connectionId);
    }
}
