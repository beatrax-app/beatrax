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
use Modules\Core\Public\Support\SafeExceptionContext;
use Psr\Log\LoggerInterface;
use Throwable;

// Scoped to THIS device, which the settings copy says: there is no Beatrax
// server, and a paired household device keeps its own replica. What is
// guaranteed is that the account cannot return -- identity and keyring go too.

// Deleting the administrator while a partner remains would leave a device
// nobody can administer behind a closed signup route, so the oldest survivor
// is promoted in the same transaction.
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

        $deviceIds = $this->deviceIdsOf($connection, $userId);
        $successorId = $this->successorAdministratorId($connection, $user);

        $connection->transaction(function () use ($connection, $userId, $successorId, $deviceIds): void {
            if ($successorId !== null) {
                $connection->table('users')->where('id', $successorId)->update(['is_developer' => true]);
            }

            // Before the rows: forgetting an enrolment writes the flag back
            // through the lock gateway, which would resurrect a deleted row.
            // Inside the transaction, though the keychain clear cannot be.
            $this->coldStartVault->forget($userId);

            ($this->purgeData)($connection, $userId, $deviceIds);
        });

        $this->settleAfterPurge($connection, $userId);
    }

    // Past the commit, where no failure can bring the account back. Swallowed
    // rather than thrown: a caller reading a post-commit throw as "rolled
    // back" told the user nothing had changed.
    private function settleAfterPurge(Connection $connection, int $userId): void
    {
        try {
            $this->rebuildSearchIndex($connection);
        } catch (Throwable $e) {
            $this->log->error('DeleteAccountAction: search index rebuild failed after the purge committed.', SafeExceptionContext::describe($e));
        }

        try {
            ($this->purgeFiles)($userId, $connection->table('users')->count() === 0);
        } catch (Throwable $e) {
            $this->log->error('DeleteAccountAction: file purge failed after the purge committed; residue left on disk.', SafeExceptionContext::describe($e));
        }

        try {
            ($this->logout)();
        } catch (Throwable $e) {
            $this->log->error('DeleteAccountAction: logout failed after the purge committed.', SafeExceptionContext::describe($e));
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
