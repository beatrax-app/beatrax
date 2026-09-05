<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;

/**
 * @link ../../../../.docs/features/ledger/architecture.md#reconciliationwriter--the-terminal-reconcile-write-path
 */
final readonly class ReconciliationWriter
{
    public function __construct(
        private DatabaseManager $db,
        private TransactionStatusWriter $status,
    ) {}

    // The reconcile flow's own vocabulary: an account and the balance date its
    // statement was printed for. The account is this class's to vouch for; the
    // column belongs to TransactionStatusWriter, which is the only thing in the
    // tree that writes it.
    /**
     * @return int the number of rows actually transitioned to `reconciled`
     *
     * @throws InvalidArgumentException when `$accountId` is not owned by `$user`.
     */
    public function completeReconcile(User $user, int $accountId, CarbonImmutable $statementDate): int
    {
        $this->assertOwnedAccount($user, $accountId);

        return $this->status->reconcileClearedUpTo($user, $accountId, $statementDate);
    }

    // A foreign or missing transaction id, or one not currently
    // reconciled, is a silent no-op.
    public function unreconcile(User $user, int $transactionId): void
    {
        $this->status->unreconcile($user, $transactionId);
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertOwnedAccount(User $user, int $accountId): void
    {
        $exists = $this->db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->where('id', $accountId)
            ->exists();

        if (! $exists) {
            throw new InvalidArgumentException('Account not owned by the authenticated user.');
        }
    }
}
