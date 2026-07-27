<?php

declare(strict_types=1);

namespace Modules\Pots\Database\Seeders\Demo;

use Modules\Core\Models\User;
use Modules\Goals\Models\Goal;
use Modules\Ledger\Models\Account;
use Modules\Pots\Models\Pot;
use Modules\Pots\Public\Services\PotWriter;

// Virtual pots carving up the demo ASN balance, three of them linked to a
// demo savings goal so the goals surface shows real contributed-vs-target
// progress. Runs after DemoGoalsSeeder, which it resolves goals from by
// name, and leaves headroom so the account still reconciles.
final class DemoPotsSeeder
{
    // goalName links the pot to a demo goal (one pot per goal is the
    // writer's rule); null leaves it free-standing. Amounts stay well
    // under the seeded ASN balance so unallocated never goes negative.
    /** @var list<array{name: string, amount: string, goalName: ?string}> */
    private const POTS = [
        ['name' => 'Emergency fund', 'amount' => '1250,00', 'goalName' => 'Emergency fund'],
        ['name' => 'Japan trip', 'amount' => '780,00', 'goalName' => 'Japan trip'],
        ['name' => 'New laptop', 'amount' => '450,00', 'goalName' => 'Replace the laptop'],
        ['name' => 'Annual insurance', 'amount' => '220,00', 'goalName' => null],
    ];

    public function __construct(
        private readonly PotWriter $writer,
    ) {}

    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        $primary = $users['demo-1@beatrax.local'] ?? null;
        if ($primary !== null) {
            $accountId = $this->currentAccountId($primary);

            if ($accountId !== null) {
                foreach (self::POTS as $row) {
                    $this->upsertPot($primary, $row, $accountId);
                }
            }
        }

        return Pot::query()
            ->whereIn('user_id', array_map(static fn (User $u): int => $u->id, $users))
            ->count();
    }

    /**
     * @param  array{name: string, amount: string, goalName: ?string}  $row
     */
    private function upsertPot(User $user, array $row, int $accountId): void
    {
        $existing = Pot::query()
            ->where('user_id', $user->id)
            ->where('name', $row['name'])
            ->first();

        if ($existing !== null) {
            return;
        }

        $goalId = null;
        if ($row['goalName'] !== null) {
            $goalId = Goal::query()
                ->where('user_id', $user->id)
                ->where('name', $row['goalName'])
                ->value('id');
            $goalId = is_numeric($goalId) ? (int) $goalId : null;
        }

        $this->writer->save($user, $row['name'], $row['amount'], $accountId, $goalId, null);
    }

    private function currentAccountId(User $user): ?int
    {
        $account = Account::query()
            ->where('user_id', $user->id)
            ->where('kind', 'asn')
            ->orderBy('id')
            ->first();

        return $account?->id;
    }
}
