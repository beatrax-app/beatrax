<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Listeners;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Events\DatabaseRestored;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\EmailScan\Internal\InboxScanStateMachine;
use Modules\EmailScan\Public\Enums\InboxScanStatus;
use Psr\Log\LoggerInterface;
use Throwable;

// The stored client secret and token blob are encrypted under the application
// key, which lives in configuration beside the database rather than inside it.
// So a restore lands rows no key on this install opens: consent at the provider
// is untouched, and the local copy of it is bytes nobody can read.
/**
 * @link ../../../../.docs/features/email-scan/a-credential-a-restore-cannot-carry.md
 */
final readonly class ClearCredentialsNoKeyHereOpens
{
    private const string TABLE = 'oauth_secrets';

    public function __construct(
        private DatabaseManager $db,
        private Encrypter $encrypter,
        private InboxScanStateMachine $state,
        private LoggerInterface $logger,
    ) {}

    public function handle(DatabaseRestored $event): void
    {
        // The restored database is one this install did not write, and a build
        // old enough to predate this table is a build whose backups a reader
        // still holds.
        if (! $this->db->connection()->getSchemaBuilder()->hasTable(self::TABLE)) {
            return;
        }

        foreach ($this->unreadable() as [$userId, $provider]) {
            try {
                $this->reset($userId, $provider);
            } catch (Throwable $e) {
                // One mailbox answering for itself. A reset that throws must
                // not leave the mailboxes after it holding credentials the
                // reader is never prompted to replace.
                $this->logger->error('A mail credential this install cannot read was not cleared.', [
                    'user_id' => $userId,
                    'provider' => $provider,
                ] + SafeExceptionContext::describe($e));
            }
        }
    }

    // Read past the model, so the decrypt is a call this asked for rather than
    // the side effect of touching a cast attribute — which is the same test
    // written as an expression that does nothing. Every row, because a restore
    // replaces every account's at once and the reader here may own none.
    /**
     * @return list<array{0: int, 1: string}>
     */
    private function unreadable(): array
    {
        $unreadable = [];

        $rows = $this->db->connection()->table(self::TABLE)
            ->select(['user_id', 'provider', 'client_secret', 'tokens_blob'])
            ->get();

        foreach ($rows as $row) {
            $userId = is_numeric($row->user_id ?? null) ? (int) $row->user_id : null;
            $provider = is_string($row->provider ?? null) ? $row->provider : null;

            if ($userId === null || $provider === null) {
                continue;
            }

            if (! $this->opensHere($row->client_secret) || ! $this->opensHere($row->tokens_blob)) {
                $unreadable[] = [$userId, $provider];
            }
        }

        return $unreadable;
    }

    // A null token blob is a row mid-authorisation, not a row this install
    // cannot read, and clearing one would disconnect a working mailbox.
    private function opensHere(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return is_string($value) && $this->decrypts($value);
    }

    // `false` for unserialize, which is exactly what the cast does in
    // HasAttributes::fromEncryptedString(). Decrypting the other way round
    // opens the payload and then fails to unserialize it, which is a different
    // exception about a row that is perfectly readable.
    private function decrypts(string $value): bool
    {
        try {
            $this->encrypter->decrypt($value, false);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }

    // Removed rather than blanked: every surface that asks whether a mailbox is
    // connected asks whether a credential row exists, so one left holding
    // unreadable bytes reads as connected and suppresses the reconnect.
    private function reset(int $userId, string $provider): void
    {
        $connection = $this->db->connection();

        $inboxes = $connection->table('inboxes')
            ->where('user_id', $userId)
            ->where('provider', $provider)
            ->pluck('id')
            ->all();

        $connection->table(self::TABLE)
            ->where('user_id', $userId)
            ->where('provider', $provider)
            ->delete();

        foreach ($inboxes as $inboxId) {
            if (! is_numeric($inboxId)) {
                continue;
            }

            $this->state->applyStatus(
                (int) $inboxId,
                InboxScanStatus::NeedsReauth->value,
                'The stored credentials were encrypted under another install and cannot be read here.',
            );
        }

        $this->logger->warning('A mail credential this install cannot read was cleared by a restore.', [
            'user_id' => $userId,
            'provider' => $provider,
            'inboxes' => count($inboxes),
        ]);
    }
}
