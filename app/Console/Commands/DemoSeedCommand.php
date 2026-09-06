<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\SampleData\SampleDatasetSeeder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Public\Actions\PurgeUserDataAction;
use Modules\Core\Public\Enums\SampleDataScope;
use Modules\Ledger\Database\Seeders\Demo\DemoUsersSeeder;
use Modules\Sync\Public\Events\EntityMutated;
use Modules\Sync\Public\Events\TransactionMutated;
use Modules\Sync\Public\Services\DependentRowCascade;

// Every seeded transaction hangs off an import_runs row stamped
// source_format='demo'; --reset walks that to keep the wipe off real data.
final class DemoSeedCommand extends Command
{
    /** @var string */
    protected $signature = 'demo:seed {--reset : Tear down existing demo data before reseeding}';

    /** @var string */
    protected $description = 'Stand up a realistic-looking demo dataset (2 users, 7 accounts, 174+ transactions, chains, recurring, forecast, drift, system + scan + receipts + recovery + wizard data). Developer-only.';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly DemoUsersSeeder $users,
        private readonly PurgeUserDataAction $purgeUserData,
        private readonly DependentRowCascade $cascade,
        private readonly Container $container,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('reset') === true) {
            $this->resetDemoData();
        }

        // The two invented personas. Everything after them is the shared
        // dataset, in the order SampleDatasetSeeder owns, because the
        // in-application control needs the same one over a real account.
        $this->line('Seeding demo users…');
        $userMap = $this->users->run();
        $this->info(sprintf('  %d demo users present', count($userMap)));

        // Resolved here, not injected: Artisan builds every command to list
        // them, and the seeder reaches thirty graphs the container is told to
        // build fresh each time. Injected, they would all be frozen at boot.
        $counts = $this->container->make(SampleDatasetSeeder::class)->seed(
            $userMap,
            SampleDataScope::WholeInstall,
            function (string $step, int $count): void {
                $this->info(sprintf('  %d %s present', $count, str_replace('_', ' ', $step)));
            },
        );

        $this->newLine();
        $this->info(sprintf(
            'Demo dataset is ready: %d rows across %d kinds. Log in as demo-1 (password: demo-only).',
            array_sum($counts),
            count($counts),
        ));

        return self::SUCCESS;
    }

    // Bounded to the demo usernames and to import runs stamped
    // source_format='demo', so it is safe against a real database. One
    // transaction, because the purge reads back what it deleted and throws:
    // otherwise a failed check leaves the half-wiped database it guards against.
    private function resetDemoData(): void
    {
        $connection = $this->db->connection();
        $demoUserIds = $this->demoUserIds($connection);

        /** @var list<object> $tombstones */
        $tombstones = [];

        $connection->transaction(function () use ($connection, $demoUserIds, &$tombstones): void {
            $this->purgeDemoImportRuns($connection, $tombstones);

            foreach ($demoUserIds as $userId) {
                ($this->purgeUserData)($connection, $userId);
            }
        });

        // After the commit, never inside it: OpLogWriter opens a transaction of
        // its own, which nested becomes a savepoint the outer rollback discards
        // while the clock that stamped the op has already moved on.
        $this->announce($tombstones);

        $this->info(sprintf(
            'Reset complete: cleared %d demo users + linked demo rows.',
            count($demoUserIds),
        ));
    }

    /**
     * @return list<int>
     */
    private function demoUserIds(Connection $connection): array
    {
        $demoUserIds = $connection->table('users')
            ->whereIn('username', DemoUsersSeeder::usernames())
            ->pluck('id')
            ->all();

        // Dropped, never coerced: an id that will not read as a number is not
        // user 0, and this list is handed straight to a purge.
        return array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            array_filter($demoUserIds, static fn (mixed $id): bool => is_numeric($id)),
        ));
    }

    // Keyed by source_format rather than by owner, so a run stranded by an
    // earlier interrupted reset still clears once its user is already gone.
    // A stale row would otherwise block the re-seed on UNIQUE (user_id, fingerprint).
    /**
     * @param  list<object>  $tombstones  Filled for dispatch once the caller's transaction commits.
     */
    private function purgeDemoImportRuns(Connection $connection, array &$tombstones): void
    {
        $importRunIds = $connection->table('import_runs')
            ->where('source_format', 'demo')
            ->pluck('id')
            ->all();

        $importRunIds = array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            array_filter($importRunIds, static fn (mixed $id): bool => is_numeric($id)),
        ));
        if ($importRunIds === []) {
            return;
        }

        // The rows those transactions own go first, because the database now
        // refuses the delete rather than taking them away behind it.
        $owners = $connection->table('transactions')
            ->whereIn('import_run_id', $importRunIds)
            ->get(['id', 'user_id']);

        $byUser = [];
        foreach ($owners as $owner) {
            if (! is_numeric($owner->user_id ?? null) || ! is_numeric($owner->id ?? null)) {
                continue;
            }
            $byUser[(int) $owner->user_id][] = (int) $owner->id;
        }

        foreach ($byUser as $userId => $transactionIds) {
            $tombstones = [...$tombstones, ...$this->cascade->deleteAll('transactions', $transactionIds, $userId)];

            foreach ($transactionIds as $transactionId) {
                $tombstones[] = new TransactionMutated(
                    transactionId: $transactionId,
                    userId: $userId,
                    mutationType: 'delete',
                );
            }
        }

        $connection->table('transactions')->whereIn('import_run_id', $importRunIds)->delete();

        $tombstones = [...$tombstones, ...$this->importRunTombstones($connection, $importRunIds)];

        // A reset that told nobody was not merely unreplicated: every create op
        // for these rows stays live in this device's own log, so the next
        // rebuild hands the whole demo dataset back. The events the cascade
        // already built were being discarded.
        $connection->table('import_runs')->whereIn('id', $importRunIds)->delete();
    }

    /**
     * @param  list<int>  $importRunIds
     * @return list<object>
     */
    private function importRunTombstones(Connection $connection, array $importRunIds): array
    {
        $tombstones = [];

        // Read while the rows are still here: an import_runs tombstone needs
        // the owner the row carries, and the delete below is the last moment
        // anything can ask for it.
        $runs = $connection->table('import_runs')->whereIn('id', $importRunIds)->get(['id', 'user_id']);

        foreach ($runs as $run) {
            if (! is_numeric($run->user_id ?? null) || ! is_numeric($run->id ?? null)) {
                continue;
            }

            $tombstones[] = new EntityMutated(
                table: 'import_runs',
                pk: (int) $run->id,
                userId: (int) $run->user_id,
                mutationType: 'delete',
            );
        }

        return $tombstones;
    }

    /**
     * @param  list<object>  $tombstones
     */
    private function announce(array $tombstones): void
    {
        if ($tombstones === []) {
            return;
        }

        $events = $this->container->make(Dispatcher::class);

        foreach ($tombstones as $tombstone) {
            $events->dispatch($tombstone);
        }
    }
}
