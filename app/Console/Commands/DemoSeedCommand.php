<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Modules\Chains\Database\Seeders\Demo\DemoChainsSeeder;
use Modules\Counterparties\Database\Seeders\Demo\DemoCounterpartiesSeeder;
use Modules\Forecasting\Database\Seeders\Demo\DemoForecastSeeder;
use Modules\Ledger\Database\Seeders\Demo\DemoAccountsSeeder;
use Modules\Ledger\Database\Seeders\Demo\DemoTransactionsSeeder;
use Modules\Ledger\Database\Seeders\Demo\DemoUsersSeeder;
use Modules\Recurring\Database\Seeders\Demo\DemoRecurringSeeder;

/**
 * Orchestrates the demo-dataset pipeline behind the `demo:seed`
 * artisan command. The command is a developer-only tool — it stands
 * up two demo users, five accounts, ~150 transactions across the
 * recent 90-day window, two pre-resolved funding chains, four
 * recurring detections, and one forecast scenario. It is the
 * canonical path to a realistic-looking install for screenshot
 * capture, onboarding shake-out, and dev-experience parity.
 *
 * Isolation: every transaction the command writes belongs to an
 * `import_runs` row stamped `source_format = 'demo'`. `--reset` walks
 * that marker first so the wipe touches only demo-tagged rows; real
 * user data on the same database is never affected.
 *
 * Idempotency: every seeder in the chain is idempotent. Running
 * `php artisan demo:seed` twice produces identical row counts (no
 * duplicates, no exceptions). `--reset` is the explicit "tear down
 * and rebuild" path; the bare invocation is the "ensure exists" path.
 *
 * Each phase prints a single console line so the command is also a
 * learning artifact — a contributor reading the output sees the
 * graph of demo data being constructed in order.
 */
final class DemoSeedCommand extends Command
{
    /** @var string */
    protected $signature = 'demo:seed {--reset : Tear down existing demo data before reseeding}';

    /** @var string */
    protected $description = 'Stand up a realistic-looking demo dataset (2 users, 5 accounts, ~150 transactions, chains, recurring, forecast). Developer-only.';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly DemoUsersSeeder $users,
        private readonly DemoAccountsSeeder $accounts,
        private readonly DemoTransactionsSeeder $transactions,
        private readonly DemoCounterpartiesSeeder $counterparties,
        private readonly DemoChainsSeeder $chains,
        private readonly DemoRecurringSeeder $recurring,
        private readonly DemoForecastSeeder $forecast,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('reset') === true) {
            $this->resetDemoData();
        }

        $this->line('Seeding demo users…');
        $userMap = $this->users->run();
        $this->info(sprintf('  %d demo users present', count($userMap)));

        $this->line('Seeding demo accounts…');
        $accountMap = $this->accounts->run($userMap);
        $accountCount = 0;
        foreach ($accountMap as $perUser) {
            $accountCount += count($perUser);
        }
        $this->info(sprintf('  %d demo accounts present', $accountCount));

        $this->line('Seeding demo transactions (~150 rows across 90 days)…');
        $txCount = $this->transactions->run($userMap, $accountMap);
        $this->info(sprintf('  %d demo transactions present', $txCount));

        $this->line('Resolving counterparties for demo transactions…');
        $cpCount = $this->counterparties->run($userMap);
        $this->info(sprintf('  %d demo counterparties present', $cpCount));

        $this->line('Building pre-resolved demo chains (PayPal→ASN, ICS→ASN)…');
        $chainCount = $this->chains->run($userMap, $accountMap);
        $this->info(sprintf('  %d demo chain links present', $chainCount));

        $this->line('Registering recurring-detection demo series…');
        $recurringCount = $this->recurring->run($userMap);
        $this->info(sprintf('  %d demo recurring series present', $recurringCount));

        $this->line('Creating baseline forecast scenario…');
        $forecastCount = $this->forecast->run($userMap);
        $this->info(sprintf('  %d demo forecast scenarios present', $forecastCount));

        $this->newLine();
        $this->info('Demo dataset is ready. Log in as demo-1@beatrax.local (password: demo-only).');

        return self::SUCCESS;
    }

    /**
     * Wipe every row the demo seeder has previously created. The query
     * builder is used directly so the deletion order honours the FK
     * dependency graph (transactions → import_runs → counterparties →
     * chain_links → recurring_series → forecast_scenarios → accounts →
     * users) and SQLite's `ON DELETE CASCADE` collapses the trailing
     * deletions for us.
     *
     * The wipe is bounded to demo data exclusively:
     *   - transactions / import_runs by `source_format = 'demo'`
     *   - counterparties / chain_links / recurring_series /
     *     forecast_scenarios / accounts by membership in the demo
     *     users' id set
     *   - users themselves by the `@beatrax.local` username suffix
     *     plus the deterministic `demo-` prefix
     *
     * The `@beatrax.local` suffix is the contract — every demo user
     * uses it, and no real-user signup should ever use that
     * artificial top-level. A future "delete-all-demo-data" UI button
     * (currently out of scope) consumes the same wipe primitive.
     */
    private function resetDemoData(): void
    {
        $connection = $this->db->connection();

        $demoUserIds = $connection->table('users')
            ->where('username', 'like', 'demo-%@beatrax.local')
            ->pluck('id')
            ->all();

        $demoUserIds = array_map(
            static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
            $demoUserIds,
        );

        // Transactions cascade-delete via import_runs FK, but the
        // composite UNIQUE index on transactions(user_id, fingerprint)
        // means a stale row with the same fingerprint would block a
        // re-seed if a demo user is wiped while keeping their tx
        // history. Wipe transactions explicitly first so the order is
        // observable in the logs (and a future contributor can spot a
        // missed cascade without diffing the SQLite schema).
        $importRunIds = $connection->table('import_runs')
            ->where('source_format', 'demo')
            ->pluck('id')
            ->all();

        if ($importRunIds !== []) {
            $importRunIds = array_map(
                static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
                $importRunIds,
            );
            $connection->table('transactions')
                ->whereIn('import_run_id', $importRunIds)
                ->delete();
            $connection->table('import_runs')
                ->whereIn('id', $importRunIds)
                ->delete();
        }

        if ($demoUserIds !== []) {
            // Tables that have a `user_id` column carrying the demo
            // users' id but whose rows are not necessarily owned by an
            // ImportRun. The schema's cascade-on-delete from users
            // would handle these automatically when the user row is
            // deleted below; we wipe them explicitly here so a partial
            // reset (e.g. a manual `DELETE FROM accounts WHERE …`
            // halfway through development) still finishes the job.
            $connection->table('chain_links')
                ->whereIn('user_id', $demoUserIds)
                ->delete();
            $connection->table('recurring_series')
                ->whereIn('user_id', $demoUserIds)
                ->delete();
            $connection->table('forecast_scenarios')
                ->whereIn('user_id', $demoUserIds)
                ->delete();
            $connection->table('counterparties')
                ->whereIn('user_id', $demoUserIds)
                ->delete();
            $connection->table('accounts')
                ->whereIn('user_id', $demoUserIds)
                ->delete();
            $connection->table('users')
                ->whereIn('id', $demoUserIds)
                ->delete();
        }

        $this->info(sprintf(
            'Reset complete: cleared %d demo users + linked demo rows.',
            count($demoUserIds),
        ));
    }
}
