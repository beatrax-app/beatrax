<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Http\Controllers;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\EmailScan\Internal\OAuth\GoogleOAuthProvider;
use Modules\EmailScan\Internal\OAuth\InvalidGrantException;
use Modules\EmailScan\Internal\OAuth\InvalidStateException;
use Modules\EmailScan\Internal\OAuth\MicrosoftOAuthProvider;
use Modules\EmailScan\Internal\OAuth\OAuthExchangeFailed;
use Modules\EmailScan\Internal\OAuth\OAuthStateRepository;
use Modules\EmailScan\Public\LoopbackRedirectUri;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use Modules\EmailScan\Public\Services\SecretsWriteFailed;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @link ../../../../../.docs/features/email-scan/architecture.md
 */
final class OAuthCallbackController
{
    public function __construct(
        private readonly GoogleOAuthProvider $googleOAuth,
        private readonly MicrosoftOAuthProvider $microsoftOAuth,
        private readonly OAuthSecretsRepository $secrets,
        private readonly OAuthStateRepository $oauthState,
        private readonly CurrentUser $currentUser,
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly Redirector $redirector,
        private readonly LoopbackRedirectUri $loopback,
    ) {}

    public function __invoke(Request $request, string $provider): RedirectResponse
    {
        $oauth = match ($provider) {
            'gmail' => $this->googleOAuth,
            'microsoft' => $this->microsoftOAuth,
            default => throw new NotFoundHttpException('Unknown provider.'),
        };

        $errorParam = $request->query('error');
        if (is_string($errorParam) && $errorParam !== '') {
            $description = $request->query('error_description');
            $message = is_string($description) && $description !== ''
                ? $description
                : $errorParam;

            return $this->redirector
                ->route('inboxes.index')
                ->with('oauth_canceled', $message);
        }

        // Resolve the current user before consuming the state, so the
        // consume call can verify the state's stored user_id matches —
        // a mismatch tears down the flow with InvalidStateException,
        // since the inbox must only attach to the initiating user.
        $user = $this->currentUser->user();
        $userId = $user->id;

        $stateParamRaw = $request->query('state');
        $stateParam = is_string($stateParamRaw) ? $stateParamRaw : '';
        $existingInboxId = $this->oauthState->consumeState($provider, $stateParam, $userId);
        if ($existingInboxId === null) {
            throw new InvalidStateException('OAuth state mismatch.');
        }

        $codeRaw = $request->query('code');
        $code = is_string($codeRaw) ? $codeRaw : '';
        if ($code === '') {
            return $this->redirector
                ->route('inboxes.index')
                ->with('oauth_failed', 'OAuth callback returned no authorization code.');
        }

        $redirectUri = $this->loopback->forProvider($provider);

        try {
            $tokenWithEmail = $oauth->exchangeAuthorizationCode($code, $redirectUri);
        } catch (InvalidGrantException $e) {
            return $this->redirector
                ->route('inboxes.index')
                ->with('oauth_failed', $e->getMessage());
        } catch (OAuthExchangeFailed $e) {
            return $this->redirector
                ->route('inboxes.index')
                ->with('oauth_failed', $e->getMessage());
        }

        $now = $this->clock->now()->toDateTimeString();
        $email = $tokenWithEmail->email;
        $refreshToken = $tokenWithEmail->refreshToken;
        $expiresAt = $tokenWithEmail->expiresAt;

        // Refuse to persist a brand-new inbox without a refresh token —
        // the next IncrementalScanJob would mark it needs_reauth on
        // first run. For Google this typically means the consent
        // screen is still in "Testing" status; surface a flash instead.
        if ($existingInboxId === 0 && ($refreshToken === null || $refreshToken === '')) {
            return $this->redirector
                ->route('inboxes.index')
                ->with(
                    'oauth_failed',
                    $provider === 'gmail'
                        ? 'Google did not return a refresh token. Publish your OAuth consent screen to "In production" and try again.'
                        : 'The provider did not return a refresh token. Reconnect and grant offline access when prompted.',
                );
        }

        $inboxId = $this->db->connection()->transaction(function () use (
            $existingInboxId, $userId, $provider, $email, $now,
        ): int {
            $connection = $this->db->connection();
            $connection->statement('PRAGMA busy_timeout = 5000');

            if ($existingInboxId > 0) {
                $affected = $connection->table('inboxes')
                    ->where('id', $existingInboxId)
                    ->where('user_id', $userId)
                    ->update([
                        'email' => $email,
                        'updated_at' => $now,
                    ]);

                if ($affected === 0) {
                    throw new NotFoundHttpException('Inbox not found.');
                }

                return $existingInboxId;
            }

            $newId = $connection->table('inboxes')->insertGetId([
                'user_id' => $userId,
                'provider' => $provider,
                'email' => $email,
                'backfill_window_months' => 3,
                'backfill_progress' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $connection->table('inbox_scan_state')->insert([
                'user_id' => $userId,
                'inbox_id' => $newId,
                'folder' => 'INBOX',
                'status' => 'idle',
                'retry_attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $newId;
        });

        // The chmod-600 JSON write happens after the DB commit, since
        // the inbox id is only assigned by insertGetId(); a write
        // failure on the new-inbox branch deletes both just-inserted
        // rows, while the reconnect branch just surfaces a flash.
        try {
            if ($existingInboxId > 0) {
                if ($refreshToken !== null) {
                    $this->secrets->rotateRefreshToken(
                        inboxId: $inboxId,
                        newRefreshToken: $refreshToken,
                        newAccessToken: $tokenWithEmail->accessToken,
                        expiresAt: $expiresAt,
                    );
                }
            } else {
                // Already guarded above: $refreshToken is non-null and
                // non-empty by this branch (the early-return rejects
                // null / '' on the new-inbox path).
                $this->secrets->saveInboxRefreshToken(
                    inboxId: $inboxId,
                    provider: $provider,
                    email: $email,
                    refreshToken: (string) $refreshToken,
                    scope: $tokenWithEmail->scope,
                    expiresAt: $expiresAt,
                );
            }
        } catch (SecretsWriteFailed $e) {
            if ($existingInboxId === 0) {
                // Compensating rollback for the just-inserted rows so
                // a failed secret write does not leave a ghost inbox
                // visible on /inboxes with no credentials.
                $this->db->connection()->transaction(function () use ($inboxId, $userId): void {
                    $connection = $this->db->connection();
                    $connection->statement('PRAGMA busy_timeout = 5000');
                    $connection->table('inbox_scan_state')
                        ->where('inbox_id', $inboxId)
                        ->where('user_id', $userId)
                        ->delete();
                    $connection->table('inboxes')
                        ->where('id', $inboxId)
                        ->where('user_id', $userId)
                        ->delete();
                });
            }

            return $this->redirector
                ->route('inboxes.index')
                ->with('oauth_failed', $e->getMessage());
        }

        // Clears any active oauth_reconsent_required banner row for
        // this inbox so the SystemAlertsBanner stops surfacing the
        // Reconnect prompt the moment the dance completes.
        $this->acknowledgeReconsentAlerts($userId, $inboxId);

        $redirect = $this->redirector
            ->route('inboxes.index')
            ->with('open_backfill_modal', $inboxId);

        if ($existingInboxId > 0) {
            // Reconnect path: signal the SystemAlertsBanner-aware
            // surface to also self-acknowledge (defence in depth) so a
            // future refactor that moves alert resolution off the
            // callback still sees the signal land at /inboxes.
            $redirect = $redirect->with('oauth_reconnect_acknowledged', $inboxId);
        }

        return $redirect;
    }

    // Acknowledges every active oauth_reconsent_required system_alerts
    // row scoped to (user_id, inbox_id) via a single UPDATE, skipping
    // already-acknowledged rows through the WHERE predicate.
    private function acknowledgeReconsentAlerts(int $userId, int $inboxId): void
    {
        $now = $this->clock->now()->toDateTimeString();
        $connection = $this->db->connection();

        $base = $connection->table('system_alerts')
            ->where('user_id', $userId)
            ->where('kind', 'oauth_reconsent_required')
            ->whereNull('acknowledged_at');

        try {
            (clone $base)
                ->whereRaw("json_extract(metadata, '$.inbox_id') = ?", [$inboxId])
                ->update(['acknowledged_at' => $now]);
        } catch (\Throwable) {
            // Fallback for SQLite builds without JSON1 — same shape as
            // the listener's de-dup fallback. Anchor the trailing
            // boundary with separate comma + brace needles so
            // `inbox_id=1` does not collide with `inbox_id=10`.
            $withComma = '%"inbox_id":'.$inboxId.',%';
            $withBrace = '%"inbox_id":'.$inboxId.'}%';
            (clone $base)
                ->where(static function (Builder $q) use ($withComma, $withBrace): void {
                    $q->where('metadata', 'like', $withComma)
                        ->orWhere('metadata', 'like', $withBrace);
                })
                ->update(['acknowledged_at' => $now]);
        }
    }
}
