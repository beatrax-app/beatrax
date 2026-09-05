<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Actions;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Internal\Account\UserScopedFilePurge;
use Modules\Auth\Public\Contracts\ColdStartVault;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeExceptionContext;
use Psr\Log\LoggerInterface;
use Throwable;

// Scoped to THIS device, which the settings copy says: there is no Beatrax
// server, and a paired household device keeps its own replica. What is
// guaranteed is that the account cannot return -- identity and keyring go too.

// Deleting the administrator while a partner remains would leave a device
// nobody can administer behind a closed signup route, so the oldest survivor
// is promoted in the same transaction.
final readonly class DeleteAccountAction
{
    public function __construct(
        private DatabaseManager $db,
        private Hasher $hasher,
        private PurgeUserDataAction $purgeData,
        private UserScopedFilePurge $purgeFiles,
        private ColdStartVault $coldStartVault,
        private LogoutAction $logout,
        private LoggerInterface $log,
    ) {}

    public function __invoke(User $user, string $password): void
    {
        // An empty box is not a wrong answer. Reported as an incorrect password
        // it sends the reader off to check a password manager, when what is
        // wrong is the field in front of them.
        if ($password === '') {
            throw ValidationException::withMessages([
                'password' => Lang::get('auth::delete_account.error_password_required'),
            ]);
        }

        if (! $this->hasher->check($password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => Lang::get('auth::delete_account.error_password'),
            ]);
        }

        $userId = $user->id;
        $connection = $this->db->connection();

        $successorId = $this->successorAdministratorId($connection, $user);

        $lastAccountOnDevice = $connection->transaction(function () use ($connection, $userId, $successorId): bool {
            if ($successorId !== null) {
                $connection->table('users')->where('id', $successorId)->update(['is_developer' => true]);
            }

            // Before the rows: forgetting an enrolment writes the flag back
            // through the lock gateway, which would resurrect a deleted row.
            // Inside the transaction, though the keychain clear cannot be.
            $this->coldStartVault->forget($userId);

            ($this->purgeData)($connection, $userId);

            // Beside the keychain clear, and irreversible on the same terms.
            // A peer holds the history; what stops it putting the account back
            // is that this device no longer holds the identity or the keyring,
            // so a deletion reported over surviving key material is the defect.
            $this->purgeFiles->keyedToTheAccount($userId);

            return $connection->table('users')->count() === 0;
        });

        $this->settleAfterPurge($connection, $userId, $lastAccountOnDevice);
    }

    // Past the commit, where no failure can bring the account back, and only
    // for what a peer cannot rebuild the account from: bulk mail, and the
    // device-wide trees. Logged rather than thrown, because a caller reading a
    // post-commit throw as "rolled back" told the user nothing had changed.
    private function settleAfterPurge(Connection $connection, int $userId, bool $lastAccountOnDevice): void
    {
        try {
            $this->rebuildSearchIndex($connection);
        } catch (Throwable $e) {
            $this->log->error('DeleteAccountAction: search index rebuild failed after the purge committed.', SafeExceptionContext::describe($e));
        }

        $this->reportResidue($userId, $lastAccountOnDevice);

        try {
            ($this->logout)();
        } catch (Throwable $e) {
            $this->log->error('DeleteAccountAction: logout failed after the purge committed.', SafeExceptionContext::describe($e));
        }
    }

    // Named, not counted. "The purge failed" sent whoever read the log to
    // guess which of eight trees is still there, on the one device that holds
    // the answer.
    private function reportResidue(int $userId, bool $lastAccountOnDevice): void
    {
        try {
            $survivors = $this->purgeFiles->residue($userId, $lastAccountOnDevice);
        } catch (Throwable $e) {
            $this->log->error('DeleteAccountAction: residue purge failed after the deletion committed.', SafeExceptionContext::describe($e));

            return;
        }

        if ($survivors !== []) {
            $this->log->error('DeleteAccountAction: residue left on disk after the deletion committed.', [
                'user_id' => $userId,
                'paths' => $survivors,
            ]);
        }
    }

    // Null unless the account leaving is the only administrator and somebody
    // else is still here.
    private function successorAdministratorId(Connection $connection, User $user): ?int
    {
        if ($user->is_developer !== true) {
            return null;
        }

        $others = $connection->table('users')->where('id', '!=', $user->id);

        if ((clone $others)->where('is_developer', true)->exists()) {
            return null;
        }

        $successor = (clone $others)->orderBy('id')->value('id');

        return is_numeric($successor) ? (int) $successor : null;
    }

    // An external-content FTS5 table does not follow its content table's
    // deletes, so the descriptions would survive for the next searcher.
    private function rebuildSearchIndex(Connection $connection): void
    {
        if (! $connection->getSchemaBuilder()->hasTable('transaction_search_fts')) {
            return;
        }

        $connection->statement("INSERT INTO transaction_search_fts(transaction_search_fts) VALUES('rebuild')");
    }
}
