<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Http\Controllers;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
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
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Invokable controller routed at GET /oauth/callback/{provider} —
 * handles the IdP redirect for both the gmail and microsoft providers.
 *
 * Verifies the CSRF state, exchanges the authorization code for an
 * access + refresh token via the provider wrapper selected by
 * match($provider), persists the refresh token to the chmod-600 JSON
 * repository, inserts (or reconnect-rotates) the inboxes +
 * inbox_scan_state row pair inside a single DB transaction, and
 * redirects to /inboxes with a flash that auto-opens the backfill
 * window modal.
 *
 * The redirect URI is recomputed server-side from the same config
 * value the connect controller used so the value matches the IdP's
 * stored redirect URI exactly. A mismatch here would surface as a
 * provider rejection at token-exchange time, not a state failure.
 *
 * Provider errors at the consent screen (e.g. user canceled) arrive
 * via the `error` query parameter and are handled before the state
 * is consumed.
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
        private readonly ConfigRepository $config,
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

        $stateParamRaw = $request->query('state');
        $stateParam = is_string($stateParamRaw) ? $stateParamRaw : '';
        $existingInboxId = $this->oauthState->consumeState($provider, $stateParam);
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

        $redirectUri = $this->computeLoopbackRedirectUri($provider);

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

        $user = $this->currentUser->user();
        $userId = $user->id;
        $now = $this->clock->now()->toDateTimeString();
        $email = $tokenWithEmail->email;
        $refreshToken = $tokenWithEmail->refreshToken;
        $expiresAt = $tokenWithEmail->expiresAt;

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

        // The chmod-600 JSON write happens AFTER the DB commit so a
        // failure to write secrets cannot leave behind an orphaned
        // inboxes row pointing at a credential we never persisted.
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
            $this->secrets->saveInboxRefreshToken(
                inboxId: $inboxId,
                provider: $provider,
                email: $email,
                refreshToken: $refreshToken ?? '',
                scope: $tokenWithEmail->scope,
                expiresAt: $expiresAt,
            );
        }

        return $this->redirector
            ->route('inboxes.index')
            ->with('open_backfill_modal', $inboxId);
    }

    private function computeLoopbackRedirectUri(string $provider): string
    {
        $appUrl = $this->config->get('app.url');
        $appUrlString = is_string($appUrl) ? $appUrl : '';
        $port = parse_url($appUrlString, PHP_URL_PORT);
        $portInt = is_int($port) && $port > 0 ? $port : 8000;

        return 'http://127.0.0.1:'.$portInt.'/oauth/callback/'.$provider;
    }
}
