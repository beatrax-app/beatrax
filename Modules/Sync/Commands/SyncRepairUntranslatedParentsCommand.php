<?php

declare(strict_types=1);

namespace Modules\Sync\Commands;

use Illuminate\Console\Command;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\OwnerAccount;
use Modules\Sync\Internal\Repair\PlannedRepoint;
use Modules\Sync\Internal\Repair\UntranslatedParentRepair;

/**
 * @link ../../../.docs/features/sync/architecture.md#an-id-that-crossed-before-its-alias-existed
 */
final class SyncRepairUntranslatedParentsCommand extends Command
{
    private const string ROW_FORMAT = '  %-38s %-18s %-7s %-9s %s';

    protected $signature = 'sync:repair-untranslated-parents
        {--user= : The account to repair; defaults to the installation owner}
        {--apply : Write the repair; without it nothing on this device changes}';

    protected $description = 'Repoint the rows a peer sent whose parent ids crossed before an alias could translate them.';

    public function __construct(
        private readonly UntranslatedParentRepair $repair,
        private readonly OwnerAccount $owner,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $userId = $this->resolveUserId();

        if ($userId === null) {
            $this->error('No account to repair: pass --user, or install one first.');

            return self::FAILURE;
        }

        ['checked' => $checked, 'agreed' => $agreed, 'plans' => $plans] = $this->repair->plan($userId);

        $this->report($checked, $agreed, $plans, $userId);

        if ($plans === [] || ! $this->option('apply')) {
            $this->line($plans === [] ? 'Nothing to repair.' : 'Dry run: nothing was changed. Pass --apply to write it.');

            return self::SUCCESS;
        }

        return $this->apply($plans, $userId);
    }

    // The counted denominator first: a run that examined nothing and a run that
    // found nothing print the same line until one of them says how many ids it
    // read. Values are safe to print -- an id is a number, and every natural key
    // a match is made on is unsealed, because a match on ciphertext finds nothing.
    /**
     * @param  list<PlannedRepoint>  $plans
     */
    private function report(int $checked, int $agreed, array $plans, int $userId): void
    {
        $this->line(sprintf(
            '%d parent id(s) a peer sent were read for account %d; %d name the row the peer meant, %d do not.',
            $checked,
            $userId,
            $agreed,
            count($plans),
        ));

        if ($plans === []) {
            return;
        }

        $this->line(sprintf(self::ROW_FORMAT, 'row', 'column', 'now', 'proposed', 'evidence'));

        foreach (self::ordered($plans) as $plan) {
            $this->line(sprintf(
                self::ROW_FORMAT,
                $plan->finding->at->where(),
                $plan->finding->at->column,
                $plan->finding->storedValue ?? '—',
                $plan->writes() ? (string) $plan->finding->correctValue : '— skipped',
                $plan->writes() ? $plan->finding->evidence : $plan->action.' — '.$plan->finding->evidence,
            ));
        }
    }

    /**
     * @param  list<PlannedRepoint>  $plans
     */
    private function apply(array $plans, int $userId): int
    {
        $user = User::query()->find($userId);

        if (! $user instanceof User) {
            $this->error(sprintf('No account %d to repair.', $userId));

            return self::FAILURE;
        }

        ['repointed' => $repointed, 'aliased' => $aliased, 'refused' => $refused] = $this->repair->apply($plans, $user);

        $this->info(sprintf('%d row(s) repointed, %d alias(es) recorded so the next op translates on its own.', $repointed, $aliased));

        if ($refused > 0) {
            $this->warn(sprintf('%d row(s) the writer refused: a reconciled row and a counterparty of another reader are both a no-op there.', $refused));
        }

        return self::SUCCESS;
    }

    // By row, so the reader reads a ledger rather than the order the log
    // happened to group its pks in.
    /**
     * @param  list<PlannedRepoint>  $plans
     * @return list<PlannedRepoint>
     */
    private static function ordered(array $plans): array
    {
        usort($plans, static fn (PlannedRepoint $a, PlannedRepoint $b): int => self::sortKey($a) <=> self::sortKey($b));

        return $plans;
    }

    // The id sorts by length first, so an autoincrement's 175 comes before its
    // 216 rather than between 1 and 2 — and a derived id, which is a sixty-three
    // bit number written as text, does not scatter through them.
    /**
     * @return array{string, string, int, string}
     */
    private static function sortKey(PlannedRepoint $plan): array
    {
        return [$plan->finding->at->table, $plan->finding->at->column, strlen($plan->finding->at->localId), $plan->finding->at->localId];
    }

    private function resolveUserId(): ?int
    {
        $given = $this->option('user');

        if (is_string($given) && $given !== '') {
            return is_numeric($given) ? (int) $given : null;
        }

        return $this->owner->id();
    }
}
