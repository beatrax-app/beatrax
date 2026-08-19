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

// Deletes the caller's own account and everything it owns ON THIS DEVICE, and
// the settings copy says so: there is no Beatrax server to delete from, and a
// paired household device holds its own replica. What it does guarantee is that
// the account cannot come back — sync identity and group keyring go with it.

// The account that set the device up is its administrator, and deleting it
// while a partner remains would leave a device nobody can administer and a
// signup route that stays closed. The oldest surviving account is promoted in
// the same transaction rather than leaving that dead end.
/**
 * @link ../../../../.docs/features/auth/architecture.md
 */
final class DeleteAccountAction
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Hasher $hasher,
        private readonly UserScopedDataPurge $purgeData,
        private readonly UserScopedFilePurge $purgeFiles,
        private readonly ColdStartVault $coldStartVault,
        private readonly LogoutAction $logout,
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

        // Before the rows, not after: forgetting a biometric enrolment writes
        // the enrolment flag back through the lock gateway, and doing that
        // after the row is gone would resurrect it.
        $this->coldStartVault->forget($userId);

        $connection->transaction(function () use ($connection, $userId, $successorId, $deviceIds): void {
            if ($successorId !== null) {
                $connection->table('users')->where('id', $successorId)->update(['is_developer' => true]);
            }

            ($this->purgeData)($connection, $userId, $deviceIds);
        });

        $this->rebuildSearchIndex($connection);

        ($this->purgeFiles)($userId, $connection->table('users')->count() === 0);

        ($this->logout)();
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
