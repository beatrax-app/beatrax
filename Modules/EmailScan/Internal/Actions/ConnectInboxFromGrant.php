<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Actions;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\OAuthAlertKind;
use Modules\Core\Public\Services\SystemAlertWriter;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\EmailScan\Internal\InboxScanStateMachine;
use Modules\EmailScan\Internal\OAuth\AccessTokenWithEmail;
use Modules\EmailScan\Internal\OAuth\InboxConnectionResult;
use Modules\EmailScan\Internal\OAuth\InvalidGrantException;
use Modules\EmailScan\Internal\OAuth\MailOAuthProviders;
use Modules\EmailScan\Internal\OAuth\OAuthExchangeFailed;
use Modules\EmailScan\Public\Enums\InboxScanStatus;
use Modules\EmailScan\Public\Enums\MailProvider;
use Modules\EmailScan\Public\LoopbackRedirectUri;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use Modules\EmailScan\Public\Services\SecretsWriteFailed;
use Modules\Sync\Public\Services\DependentRowCascade;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * @link ../../../../.docs/features/email-scan/architecture.md#oauth-connect--callback-controllers
 */
final readonly class ConnectInboxFromGrant
{
    public function __construct(
        private MailOAuthProviders $providers,
        private OAuthSecretsRepository $secrets,
        private DatabaseManager $db,
        private Clock $clock,
        private LoopbackRedirectUri $loopback,
        private SystemAlertWriter $alerts,
        private InboxScanStateMachine $scanState,
        private DependentRowCascade $cascade,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(
        MailProvider $provider,
        int $userId,
        int $existingInboxId,
        string $code,
        string $pkceVerifier,
    ): InboxConnectionResult {
        $token = $this->exchangeToken($provider, $code, $pkceVerifier);

        if (is_string($token)) {
            return InboxConnectionResult::refused($token);
        }

        return $this->completeConnection($provider, $userId, $existingInboxId, $token);
    }

    // The refusals below arrive as a developer's sentence naming a provider
    // class and, for the secrets write, the statement it failed on. The reader
    // is standing on a settings screen in their own language, so what they are
    // told is chosen here and the sentence goes to the log.
    /**
     * @return AccessTokenWithEmail|string the token, or the line the reader is shown
     */
    private function exchangeToken(
        MailProvider $provider,
        string $code,
        string $pkceVerifier,
    ): AccessTokenWithEmail|string {
        if ($code === '') {
            return Lang::get('email-scan::inboxes.oauth_no_code');
        }

        try {
            return $this->providers->for($provider)->exchangeAuthorizationCode(
                $code,
                $this->loopback->forProvider($provider->value),
                $pkceVerifier,
            );
        } catch (InvalidGrantException|OAuthExchangeFailed $e) {
            // The provider refusing the grant and the exchange itself failing
            // are different causes with the same shape: a line for the log and
            // a line for the reader, neither of them the exception's own.
            $refused = $e instanceof InvalidGrantException;

            $this->logger->warning($refused
                ? 'ConnectInboxFromGrant: the authorization grant was refused.'
                : 'ConnectInboxFromGrant: the token exchange failed.', SafeExceptionContext::describe($e));

            return Lang::get($refused
                ? 'email-scan::inboxes.oauth_grant_refused'
                : 'email-scan::inboxes.oauth_exchange_failed');
        }
    }

    private function completeConnection(
        MailProvider $provider,
        int $userId,
        int $existingInboxId,
        AccessTokenWithEmail $token,
    ): InboxConnectionResult {
        $refreshToken = $token->refreshToken;

        // An inbox with no refresh token cannot scan: the first tick marks it
        // needs_reauth. A reconnect is refused for the same reason — writing
        // only the access token would report success and change nothing once
        // that token expires an hour later.
        if ($refreshToken === null || $refreshToken === '') {
            return InboxConnectionResult::refused($this->missingRefreshTokenMessage($provider));
        }

        $now = $this->clock->now()->toDateTimeString();
        $inboxId = $this->persistInbox($existingInboxId, $userId, $provider, $token->email, $now);

        // The secrets write can only follow the commit — insertGetId() is
        // what assigns the inbox id — so a failure has to compensate by hand.
        try {
            $this->writeSecrets($existingInboxId, $inboxId, $provider, $token);
        } catch (SecretsWriteFailed $e) {
            if ($existingInboxId === 0) {
                $this->rollbackInbox($inboxId, $userId);
            }

            $this->logger->error('ConnectInboxFromGrant: the credential row was not written.', SafeExceptionContext::describe($e));

            return InboxConnectionResult::refused(Lang::get('email-scan::inboxes.oauth_not_saved'));
        }

        // Stops SystemAlertsBanner surfacing a Reconnect prompt the moment
        // the dance completes.
        $this->acknowledgeReconsentAlerts($userId, $inboxId);
        $this->clearNeedsReauth($inboxId);

        return InboxConnectionResult::connected($inboxId);
    }

    // The desktop line explains the refusal by an access token that expires
    // within the hour, which reads as a promise of hourly scanning. Nothing on
    // a phone scans on any cadence, so that half of the sentence is dropped
    // there and the step that fixes the refusal is kept.
    private function missingRefreshTokenMessage(MailProvider $provider): string
    {
        $isGoogle = $provider === MailProvider::Gmail;

        if (UserDataPathService::platform() !== null) {
            return Lang::get($isGoogle
                ? 'email-scan::inboxes.oauth_no_offline_access_google_phone'
                : 'email-scan::inboxes.oauth_no_offline_access_phone');
        }

        return Lang::get($isGoogle
            ? 'email-scan::inboxes.oauth_no_offline_access_google'
            : 'email-scan::inboxes.oauth_no_offline_access');
    }

    private function persistInbox(int $existingInboxId, int $userId, MailProvider $provider, string $email, string $now): int
    {
        return $this->db->connection()->transaction(function () use (
            $existingInboxId, $userId, $provider, $email, $now,
        ): int {
            $connection = $this->db->connection();

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
                'provider' => $provider->value,
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
                'status' => InboxScanStatus::Idle->value,
                'retry_attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $newId;
        });
    }

    // Guarded in completeConnection: refreshToken is non-null and non-empty on
    // both paths by the time this runs.
    private function writeSecrets(int $existingInboxId, int $inboxId, MailProvider $provider, AccessTokenWithEmail $token): void
    {
        if ($existingInboxId > 0) {
            $this->secrets->rotateRefreshToken(
                inboxId: $inboxId,
                newRefreshToken: (string) $token->refreshToken,
                newAccessToken: $token->accessToken,
                expiresAt: $token->expiresAt,
            );

            return;
        }

        $this->secrets->saveInboxRefreshToken(
            inboxId: $inboxId,
            provider: $provider->value,
            email: $token->email,
            refreshToken: (string) $token->refreshToken,
            scope: $token->scope,
            expiresAt: $token->expiresAt,
        );
    }

    // The whole point of the Reconnect button: needs_reauth is terminal for
    // both scan jobs, so a rotated secret on a row still marked needs_reauth
    // is a working grant on a dead inbox. Only that status is lifted — a row
    // mid-backfill keeps its own lifecycle.
    private function clearNeedsReauth(int $inboxId): void
    {
        $current = $this->db->connection()
            ->table('inbox_scan_state')
            ->where('inbox_id', $inboxId)
            ->where('folder', 'INBOX')
            ->value('status');

        if ($current === InboxScanStatus::NeedsReauth->value) {
            $this->scanState->applyStatus($inboxId, InboxScanStatus::Idle->value);
        }
    }

    // So a failed secret write does not leave a ghost inbox visible on
    // /inboxes with no credentials behind it.
    private function rollbackInbox(int $inboxId, int $userId): void
    {
        $this->db->connection()->transaction(function () use ($inboxId, $userId): void {
            $connection = $this->db->connection();
            $this->cascade->delete('inboxes', $inboxId, $userId);
            $connection->table('inboxes')
                ->where('id', $inboxId)
                ->where('user_id', $userId)
                ->delete();
        });
    }

    private function acknowledgeReconsentAlerts(int $userId, int $inboxId): void
    {
        $now = $this->clock->now()->toDateTimeString();
        $connection = $this->db->connection();

        $base = $connection->table('system_alerts')
            ->where('user_id', $userId)
            ->where('kind', OAuthAlertKind::ReconsentRequired->value)
            ->whereNull('acknowledged_at');

        try {
            $matching = (clone $base)
                ->whereRaw("json_extract(metadata, '$.inbox_id') = ?", [$inboxId]);
            $ids = (clone $matching)->pluck('id');
            $matching->update(['acknowledged_at' => $now]);
        } catch (Throwable) {
            // SQLite builds without JSON1: anchor the trailing boundary with
            // separate comma + brace needles so `inbox_id=1` cannot collide
            // with `inbox_id=10`.
            $withComma = '%"inbox_id":'.$inboxId.',%';
            $withBrace = '%"inbox_id":'.$inboxId.'}%';
            $matching = (clone $base)
                ->where(static function (Builder $q) use ($withComma, $withBrace): void {
                    $q->where('metadata', 'like', $withComma)
                        ->orWhere('metadata', 'like', $withBrace);
                });
            $ids = (clone $matching)->pluck('id');
            $matching->update(['acknowledged_at' => $now]);
        }

        // Reconnecting is the same user action as the banner's Acknowledge,
        // so it has to sync the same way — left uncaptured, the other device
        // kept prompting for an inbox already reconnected.
        foreach ($ids as $id) {
            if (is_numeric($id)) {
                $this->alerts->captureAcknowledgement((int) $id, $userId, $now);
            }
        }
    }
}
