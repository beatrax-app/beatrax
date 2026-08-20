<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Actions;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Internal\Account\UserScopedDataPurge;
use Modules\Auth\Internal\Account\UserScopedFilePurge;
use Modules\Auth\Public\Contracts\ColdStartVault;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Psr\Log\LoggerInterface;
use Throwable;

// Deletes the caller's own account and everything it owns ON THIS DEVICE, and
// the settings copy says so: there is no Beatrax server to delete from, and a
// paired household device holds its own replica. What it does guarantee is that
// the account cannot come back — sync identity and group keyring go with it.

// The account that set the device up is its administrator, and deleting it
// while a partner remains would leave a device nobody can administer and a
// signup route that stays closed. The oldest surviving account is promoted in
// the same transaction rather than leaving that dead end.
final class DeleteAccountAction
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Hasher $hasher,
        private readonly UserScopedDataPurge $purgeData,
        private readonly UserScopedFilePurge $purgeFiles,
        private readonly ColdStartVault $coldStartVault,
        private readonly LogoutAction $logout,
        private readonly LoggerInterface $log,
    ) {}

    public function __invoke(User $user, string $password): void
    {
        if (! $this->hasher->check($password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => Lang::get('auth::delete_account.error_password'),
            ]);
        }

        $userId = $user->id;
        $connection = $this->db->connection();

        $deviceIds = $this->deviceIdsOf($connection, $userId);
        $successorId = $this->successorAdministratorId($connection, $user);

        $connection->transaction(function () use ($connection, $userId, $successorId, $deviceIds): void {
            if ($successorId !== null) {
                $connection->table('users')->where('id', $successorId)->update(['is_developer' => true]);
            }

            // Before the rows, not after: forgetting an enrolment writes the
            // flag back through the lock gateway, which after the row is gone
            // would resurrect it. Inside the transaction so that write rolls
            // back too; the keychain clear and desktop unlink cannot.
            $this->coldStartVault->forget($userId);

            ($this->purgeData)($connection, $userId, $deviceIds);
        });

        $this->settleAfterPurge($connection, $userId);
    }

    // Everything past the commit, where no failure can bring the account back.
    // Logged and swallowed rather than thrown: a caller reading a post-commit
    // throw as "rolled back" told the user nothing had changed and left the
    // session naming an id that no longer resolves. A held handle suffices.
    private function settleAfterPurge(Connection $connection, int $userId): void
    {
        try {
            $this->rebuildSearchIndex($connection);
        } catch (Throwable $e) {
            $this->log->error('DeleteAccountAction: search index rebuild failed after the purge committed.', [
                'exception' => $e->getMessage(),
            ]);
        }

        try {
            ($this->purgeFiles)($userId, $connection->table('users')->count() === 0);
        } catch (Throwable $e) {
            $this->log->error('DeleteAccountAction: file purge failed after the purge committed; residue left on disk.', [
                'exception' => $e->getMessage(),
            ]);
        }

        try {
            ($this->logout)();
        } catch (Throwable $e) {
            $this->log->error('DeleteAccountAction: logout failed after the purge committed.', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /** @return list<string> this account's own device identifiers */
    private function deviceIdsOf(Connection $connection, int $userId): array
    {
        if (! $connection->getSchemaBuilder()->hasTable('device_registry')) {
            return [];
        }

        $deviceIds = [];

        foreach ($connection->table('device_registry')->where('user_id', $userId)->pluck('device_id') as $deviceId) {
            if (is_string($deviceId)) {
                $deviceIds[] = $deviceId;
            }
        }

        return $deviceIds;
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

    // The search index is an external-content FTS5 table, so it does not
    // follow its content table's deletes. Left alone it keeps the deleted
    // account's descriptions in the index for whoever searches next.
    private function rebuildSearchIndex(Connection $connection): void
    {
        if (! $connection->getSchemaBuilder()->hasTable('transaction_search_fts')) {
            return;
        }

        $connection->statement("INSERT INTO transaction_search_fts(transaction_search_fts) VALUES('rebuild')");
    }
}
