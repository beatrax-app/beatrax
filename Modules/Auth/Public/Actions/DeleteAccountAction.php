<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Actions;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Internal\Account\OwedKeyMaterial;
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
/**
 * @link ../../../../.docs/features/auth/user-scoped-purge.md#the-two-file-tiers-and-why-neither-is-inside-the-transaction
 */
final readonly class DeleteAccountAction
{
    public function __construct(
        private DatabaseManager $db,
        private Hasher $hasher,
        private PurgeUserDataAction $purgeData,
        private UserScopedFilePurge $purgeFiles,
        private OwedKeyMaterial $owedKeyMaterial,
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

        $vaultKeptTheKey = false;

        $lastAccountOnDevice = $connection->transaction(function () use ($connection, $userId, $successorId, &$vaultKeptTheKey): bool {
            if ($successorId !== null) {
                $connection->table('users')->where('id', $successorId)->update(['is_developer' => true]);
            }

            // Before the rows, and it has to be inside: forgetting an enrolment
            // writes the flag back through the lock gateway, which would
            // resurrect a deleted row. A rollback cannot put the OS entry back
            // either, but a PIN-only unlock is not an unreadable ledger.
            $vaultKeptTheKey = ! $this->coldStartVault->forget($userId);

            ($this->purgeData)($connection, $userId);

            // The three unlinks are past the commit, and what commits here is
            // the debt for them. A rollback takes the debt with the rows, so
            // the account comes back whole rather than back over keys the
            // transaction had already destroyed; a crash leaves the sweep this.
            $this->owedKeyMaterial->claim($connection, $userId);

            return $connection->table('users')->count() === 0;
        });

        $this->settleAfterPurge($connection, $userId, $lastAccountOnDevice, $vaultKeptTheKey);
    }

    // Past the commit, where no failure can bring the account back. Everything
    // here is logged rather than thrown, because a caller reading a post-commit
    // throw as "rolled back" told the user nothing had changed -- which is the
    // sentence the file unlinks moved out of the transaction to stop telling.
    private function settleAfterPurge(Connection $connection, int $userId, bool $lastAccountOnDevice, bool $vaultKeptTheKey): void
    {
        $this->reportKeyMaterialThatOutlivedTheAccount($connection, $userId, $vaultKeptTheKey);

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

    // Both halves of one fact: what a paired peer could still put this account
    // back through. The paths are owed until the sweep clears them, and are
    // named rather than counted; the vault's refusal is the arm nothing can
    // retry, which is why it is a warning beside an error.
    private function reportKeyMaterialThatOutlivedTheAccount(Connection $connection, int $userId, bool $vaultKeptTheKey): void
    {
        if ($vaultKeptTheKey) {
            $this->log->warning('DeleteAccountAction: the OS vault kept its wrapped copy of the data key, which now outlives the account it belonged to.', [
                'user_id' => $userId,
            ]);
        }

        try {
            $survivors = $this->owedKeyMaterial->settle($connection, $userId);
        } catch (Throwable $e) {
            $this->log->error('DeleteAccountAction: the key material purge failed after the deletion committed.', SafeExceptionContext::describe($e));

            return;
        }

        if ($survivors !== []) {
            $this->log->error('DeleteAccountAction: key material outlived the deletion and is owed until the sweep clears it.', [
                'user_id' => $userId,
                'paths' => $survivors,
            ]);
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
