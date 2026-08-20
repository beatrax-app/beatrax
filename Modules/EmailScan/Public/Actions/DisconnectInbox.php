<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\OAuth\GoogleTokenRevoker;
use Modules\EmailScan\Public\Enums\MailProvider;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DisconnectInbox
{
    public function __construct(
        private readonly OAuthSecretsRepository $secrets,
        private readonly GoogleTokenRevoker $revoker,
        private readonly DatabaseManager $db,
    ) {}

    public function __invoke(int $inboxId, User $user): void
    {
        $connection = $this->db->connection();

        $row = $connection->table('inboxes')
            ->where('id', $inboxId)
            ->where('user_id', $user->id)
            ->first(['id', 'provider']);
        if ($row === null) {
            throw new NotFoundHttpException('Inbox not found.');
        }

        // Revoke server-side before the local copy goes, so a leaked refresh
        // token cannot outlive the disconnect. Only Gmail exposes a revoke
        // endpoint; Microsoft consent is withdrawn by the user in their own
        // account, so there the local delete is the whole story.
        $credentials = $this->secrets->loadInbox($inboxId);
        if ($credentials !== null && $credentials->provider === MailProvider::Gmail->value) {
            $this->revoker->revoke($credentials->refreshToken);
        }

        $this->secrets->removeInbox($inboxId);

        // Scan state, fetched messages and discovered senders all cascade on
        // the inbox delete, so removing this row clears the whole inbox.
        $connection->table('inboxes')
            ->where('id', $inboxId)
            ->where('user_id', $user->id)
            ->delete();
    }
}
