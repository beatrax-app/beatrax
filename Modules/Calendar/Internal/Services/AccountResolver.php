<?php

declare(strict_types=1);

namespace Modules\Calendar\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Enums\AccountKind;
use stdClass;

/**
 * @link ../../../../.docs/features/calendar/architecture.md#account-preference-resolution
 */
final readonly class AccountResolver
{
    use CoercesScalars;

    public function __construct(private DatabaseManager $db) {}

    /**
     * @return list<int>
     */
    public function ownedAccountIds(User $user): array
    {
        $rows = $this->db->connection()->table('accounts')
            ->where('user_id', $user->id)
            ->pluck('id');

        $ids = [];
        foreach ($rows as $id) {
            $ids[] = self::toInt($id);
        }

        return $ids;
    }

    /**
     * @param  list<int>|null  $callerIds
     * @param  list<int>  $ownedIds
     * @return list<int>|null null = all visible
     */
    public function resolveVisibleAccountIds(?array $callerIds, array $ownedIds): ?array
    {
        if ($callerIds === null) {
            return null;
        }

        return array_values(array_intersect($callerIds, $ownedIds));
    }

    /**
     * @param  list<int>|null  $callerIds
     * @param  list<int>  $ownedIds
     * @return list<int>
     */
    public function resolveBalanceAccountIds(?array $callerIds, array $ownedIds, User $user): array
    {
        if ($callerIds === null) {
            return $this->spendableAccountIds($user);
        }

        return array_values(array_intersect($callerIds, $ownedIds));
    }

    // Which kinds those are, and why each of the other three is outside them,
    // is AccountKind's to answer: the balance line, net worth and the reports
    // series each kept their own list, and the one that had paypal_funding in
    // it handed the reader back money their bank had already paid out.
    /**
     * @return list<int>
     */
    public function spendableAccountIds(User $user): array
    {
        $rows = $this->db->connection()->table('accounts')
            ->where('user_id', $user->id)
            ->whereIn('kind', AccountKind::spendableValues())
            ->pluck('id');

        $ids = [];
        foreach ($rows as $id) {
            $ids[] = self::toInt($id);
        }

        return $ids;
    }

    /**
     * @return array<int, string>
     */
    public function accountNamesForUser(User $user): array
    {
        $rows = $this->db->connection()->table('accounts')
            ->where('user_id', $user->id)
            ->get(['id', 'name']);

        $map = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $map[self::toInt($row->id)] = self::toString($row->name ?? null);
        }

        return $map;
    }
}
