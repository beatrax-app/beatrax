<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\OAuth\GoogleTokenRevoker;
use Modules\EmailScan\Public\Enums\MailProvider;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use Modules\Sync\Public\Services\DependentRowCascade;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class DisconnectInbox
{
    public function __construct(
        private OAuthSecretsRepository $secrets,
        private GoogleTokenRevoker $revoker,
        private DatabaseManager $db,
        private Dispatcher $events,
        private DependentRowCascade $cascade,
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

        // Revoked before the local copy goes, so a leaked refresh token
        // cannot outlive the disconnect. Only Gmail exposes a revoke endpoint;
        // Microsoft consent is withdrawn by the user in their own account.
        $credentials = $this->secrets->loadInbox($inboxId);
        if ($credentials !== null && $credentials->provider === MailProvider::Gmail->value) {
            $this->revoker->revoke($credentials->refreshToken);
        }

        $this->secrets->removeInbox($inboxId);

        // Scan state, fetched messages and discovered senders are the inbox's
        // to remove. Leaving that to the database would clear them without
        // telling anything, which is how a row outlives its own deletion.
        $dependents = $this->cascade->delete('inboxes', $inboxId, $user->id);

        $connection->table('inboxes')
            ->where('id', $inboxId)
            ->where('user_id', $user->id)
            ->delete();

        foreach ($dependents as $event) {
            $this->events->dispatch($event);
        }
    }
}
