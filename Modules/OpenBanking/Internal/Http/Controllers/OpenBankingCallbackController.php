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
 * @link ../../../../../.docs/features/open-banking/architecture.md
 */
final class OpenBankingCallbackController
{
    // Kept in sync with the identically-named constant on
    // OpenBankingConnectController.
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

        // Resolve the current user before consuming the state so the
        // consume call can verify the state's stored user_id matches.
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
        // does not invalidate the completed consent — a later fetch
        // attempt reports its own explicit error instead.
        $accountUid = $this->client->accountUidFrom($session);

        $institutionId = $credentials->institutionId;
        $now = $this->clock->now();
        $nowString = $now->toDateTimeString();
        $consentExpiresAt = $now->addDays(self::CONSENT_VALID_FOR_DAYS);
        $consentExpiresAtString = $consentExpiresAt->toDateTimeString();

        $existingRow = $this->db->connection()->table('open_banking_connections')
            ->where('user_id', $userId)
            ->where('institution_id', $institutionId)
            ->first(['id', 'consent_expires_at', 'account_uid']);
        $existingId = ($existingRow !== null && is_numeric($existingRow->id)) ? (int) $existingRow->id : null;
        $isNew = $existingId === null;

        // Snapshot the pre-update values so the re-link (update) path can
        // restore them if the secrets write below fails — otherwise the row
        // would advertise a fresh consent the secrets file cannot back.
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

        // The chmod-600 JSON write happens after the DB commit: a failure
        // here needs an explicit compensating rollback of the row just
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
            $this->db->connection()->transaction(function () use (
                $isNew, $connectionId, $userId, $priorConsentExpiresAt, $priorAccountUid, $nowString,
            ): void {
                $connection = $this->db->connection();
                $connection->statement('PRAGMA busy_timeout = 5000');

                if ($isNew) {
                    $connection->table('open_banking_connections')
                        ->where('id', $connectionId)
                        ->where('user_id', $userId)
                        ->delete();

                    return;
                }

                // Re-link path — roll the row back to its pre-update
                // consent_expires_at/account_uid so it never advertises a
                // fresh consent the secrets file cannot back.
                $connection->table('open_banking_connections')
                    ->where('id', $connectionId)
                    ->where('user_id', $userId)
                    ->update([
                        'consent_expires_at' => $priorConsentExpiresAt,
                        'account_uid' => $priorAccountUid,
                        'updated_at' => $nowString,
                    ]);
            });

            return $this->redirector
                ->route('settings.open-banking')
                ->with('open_banking_failed', $e->getMessage());
        }

        return $this->redirector
            ->route('settings.open-banking')
            ->with('open_banking_connected', $connectionId);
    }
}
