<?php

declare(strict_types=1);

namespace Modules\Pots\Database\Seeders\Demo;

use Modules\Core\Models\User;
use Modules\Core\Public\Support\DemoNames;
use Modules\Core\Public\Support\Lang;
use Modules\Goals\Models\Goal;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Pots\Models\Pot;
use Modules\Pots\Public\Services\PotWriter;

// Runs after DemoGoalsSeeder — it resolves goals by name — and keeps the pot
// amounts under the seeded account balance so unallocated never goes negative.
final class DemoPotsSeeder
{
    /** @var list<array{nameKey: string, amount: string, goalKey: ?string}> */
    private const POTS = [
        ['nameKey' => 'pot_emergency_fund', 'amount' => '1250,00', 'goalKey' => 'goal_emergency_fund'],
        ['nameKey' => 'pot_japan_trip', 'amount' => '780,00', 'goalKey' => 'goal_japan_trip'],
        ['nameKey' => 'pot_new_laptop', 'amount' => '450,00', 'goalKey' => 'goal_replace_laptop'],
        ['nameKey' => 'pot_annual_insurance', 'amount' => '220,00', 'goalKey' => null],
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
     * @param  array{nameKey: string, amount: string, goalKey: ?string}  $row
     */
    private function upsertPot(User $user, array $row, int $accountId): void
    {
        $name = Lang::get('core::demo.'.$row['nameKey']);

        // Every locale's rendering, not just today's: the dedupe key is a
        // translated string, so a re-seed in another language duplicated it.
        $existing = Pot::query()
            ->where('user_id', $user->id)
            ->whereIn('name', DemoNames::everyRendering($row['nameKey']))
            ->first();

        if ($existing !== null) {
            return;
        }

        $goalId = null;
        if ($row['goalKey'] !== null) {
            // The goal may have been seeded in another language; resolving by
            // today's rendering alone left the pot unlinked.
            $goalId = Goal::query()
                ->where('user_id', $user->id)
                ->whereIn('name', DemoNames::everyRendering($row['goalKey']))
                ->value('id');
            $goalId = is_numeric($goalId) ? (int) $goalId : null;
        }

        $this->writer->save($user, $name, $row['amount'], $accountId, $goalId, null);
    }

    private function currentAccountId(User $user): ?int
    {
        $account = Account::query()
            ->where('user_id', $user->id)
            ->where('kind', AccountKind::Bank->value)
            ->orderBy('id')
            ->first();

        return $account?->id;
    }
}
