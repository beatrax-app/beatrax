<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Modules\Anomaly\Database\Seeders\Demo\DemoAnomalyAlertsSeeder;
use Modules\Auth\Database\Seeders\Demo\DemoRecoveryCodesSeeder;
use Modules\Auth\Public\Actions\PurgeUserDataAction;
use Modules\Budgets\Database\Seeders\Demo\DemoEnvelopeBudgetsSeeder;
use Modules\CashBook\Database\Seeders\Demo\DemoCashEntriesSeeder;
use Modules\Categorization\Database\Seeders\DefaultCategoryTreeSeeder;
use Modules\Categorization\Database\Seeders\Demo\DemoCategorizationRulesSeeder;
use Modules\Categorization\Database\Seeders\Demo\DemoMerchantMemorySeeder;
use Modules\Chains\Database\Seeders\Demo\DemoChainsSeeder;
use Modules\Community\Database\Seeders\Demo\DemoCommunityMappingsSeeder;
use Modules\Core\Database\Seeders\Demo\DemoSystemAlertsSeeder;
use Modules\Core\Database\Seeders\Demo\DemoUserPreferencesSeeder;
use Modules\Counterparties\Database\Seeders\Demo\DemoCounterpartiesSeeder;
use Modules\DriftAlerts\Database\Seeders\Demo\DemoDriftAlertsSeeder;
use Modules\EmailScan\Database\Seeders\Demo\DemoEmailScanSeeder;
use Modules\Forecasting\Database\Seeders\Demo\DemoForecastSeeder;
use Modules\Goals\Database\Seeders\Demo\DemoGoalsSeeder;
use Modules\Import\Database\Seeders\Demo\DemoMerchantAliasesSeeder;
use Modules\Ledger\Database\Seeders\CurrenciesSeeder;
use Modules\Ledger\Database\Seeders\Demo\DemoAccountsSeeder;
use Modules\Ledger\Database\Seeders\Demo\DemoTransactionSplitsSeeder;
use Modules\Ledger\Database\Seeders\Demo\DemoTransactionsSeeder;
use Modules\Ledger\Database\Seeders\Demo\DemoTransferPairsSeeder;
use Modules\Ledger\Database\Seeders\Demo\DemoUsersSeeder;
use Modules\Notifications\Database\Seeders\Demo\DemoNotificationsSeeder;
use Modules\Onboarding\Database\Seeders\Demo\DemoWizardProgressSeeder;
use Modules\Pots\Database\Seeders\Demo\DemoPotsSeeder;
use Modules\Receipts\Database\Seeders\Demo\DemoReceiptsSeeder;
use Modules\Recurring\Database\Seeders\Demo\DemoRecurringSeeder;
use Modules\Reports\Database\Seeders\Demo\DemoSavedReportsSeeder;
use Modules\Sync\Public\Events\EntityMutated;
use Modules\Sync\Public\Events\TransactionMutated;
use Modules\Sync\Public\Services\DependentRowCascade;
use Modules\Tax\Database\Seeders\Demo\DemoTaxTagsSeeder;

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
        private readonly CurrenciesSeeder $currencies,
        private readonly DefaultCategoryTreeSeeder $categories,
        private readonly DemoUsersSeeder $users,
        private readonly DemoAccountsSeeder $accounts,
        private readonly DemoUserPreferencesSeeder $userPreferences,
        private readonly DemoTransactionsSeeder $transactions,
        private readonly DemoCashEntriesSeeder $cashEntries,
        private readonly DemoEnvelopeBudgetsSeeder $envelopeBudgets,
        private readonly DemoCounterpartiesSeeder $counterparties,
        private readonly DemoChainsSeeder $chains,
        private readonly DemoRecurringSeeder $recurring,
        private readonly DemoForecastSeeder $forecast,
        private readonly DemoDriftAlertsSeeder $driftAlerts,
        private readonly DemoSystemAlertsSeeder $systemAlerts,
        private readonly DemoEmailScanSeeder $emailScan,
        private readonly DemoRecoveryCodesSeeder $recoveryCodes,
        private readonly DemoCommunityMappingsSeeder $communityMappings,
        private readonly DemoWizardProgressSeeder $wizardProgress,
        private readonly DemoReceiptsSeeder $receipts,
        private readonly DemoTransferPairsSeeder $transferPairs,
        private readonly DemoMerchantMemorySeeder $merchantMemory,
        private readonly DemoMerchantAliasesSeeder $merchantAliases,
        private readonly DemoNotificationsSeeder $notifications,
        private readonly DemoGoalsSeeder $goals,
        private readonly DemoPotsSeeder $pots,
        private readonly DemoTaxTagsSeeder $taxTags,
        private readonly DemoCategorizationRulesSeeder $categorizationRules,
        private readonly DemoTransactionSplitsSeeder $transactionSplits,
        private readonly DemoSavedReportsSeeder $savedReports,
        private readonly DemoAnomalyAlertsSeeder $anomalyAlerts,
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

        $this->line('Ensuring reference data (currencies + default category tree)…');
        $this->currencies->run();
        $this->categories->run();

        $this->line('Seeding demo users…');
        $userMap = $this->users->run();
        $this->info(sprintf('  %d demo users present', count($userMap)));

        $this->line('Seeding demo user-preference rows…');
        $prefCount = $this->userPreferences->run($userMap);
        $this->info(sprintf('  %d demo user-preference rows present', $prefCount));

        $this->line('Seeding demo accounts…');
        $accountMap = $this->accounts->run($userMap);
        $accountCount = 0;
        foreach ($accountMap as $perUser) {
            $accountCount += count($perUser);
        }
        $this->info(sprintf('  %d demo accounts present', $accountCount));

        $this->line('Seeding demo transactions across each persona\'s own last three budget periods…');
        $txCount = $this->transactions->run($userMap, $accountMap);
        $this->info(sprintf('  %d demo transactions present', $txCount));

        // Before the budget assignments, which are tuned against the spend the
        // ledger holds, and before the pots, whose funding is bounded by what
        // the cash account is left holding.
        $this->line('Recording demo cash-book entries in the cash account\'s own denomination…');
        $cashEntryCount = $this->cashEntries->run($userMap);
        $this->info(sprintf('  %d demo cash-book entries present', $cashEntryCount));

        $this->line('Activating envelope budgeting + seeding three periods of assignments, settings and moves…');
        $assignmentCount = $this->envelopeBudgets->run($userMap);
        $this->info(sprintf('  %d demo envelope assignments present (demo users activated)', $assignmentCount));

        $this->line('Resolving counterparties for demo transactions…');
        $cpCount = $this->counterparties->run($userMap);
        $this->info(sprintf('  %d demo counterparties present', $cpCount));

        $this->line('Seeding additional demo merchant aliases…');
        $aliasCount = $this->merchantAliases->run($userMap);
        $this->info(sprintf('  %d demo merchant aliases present', $aliasCount));

        $this->line('Materialising the cross-account demo transfer pair…');
        $pairLegCount = $this->transferPairs->run($userMap, $accountMap);
        $this->info(sprintf('  %d demo transfer-pair legs present', $pairLegCount));

        $this->line('Building pre-resolved demo chains (PayPal→ASN, ICS→ASN) + hint candidates…');
        $chainCount = $this->chains->run($userMap, $accountMap);
        $this->info(sprintf('  %d demo chain links present', $chainCount));

        $this->line('Registering recurring-detection demo series + transitions…');
        $recurringCount = $this->recurring->run($userMap);
        $this->info(sprintf('  %d demo recurring series present', $recurringCount));

        $this->line('Materialising drift-alert demo dataset…');
        $driftCount = $this->driftAlerts->run($userMap);
        $this->info(sprintf('  %d demo drift alerts present', $driftCount));

        $this->line('Creating demo forecast scenarios + shortfall…');
        $forecastCount = $this->forecast->run($userMap);
        $this->info(sprintf('  %d demo forecast scenarios present', $forecastCount));

        $this->line('Seeding demo system-alert banner kinds…');
        $sysAlertCount = $this->systemAlerts->run($userMap);
        $this->info(sprintf('  %d demo system alerts present', $sysAlertCount));

        $this->line('Seeding demo merchant-memory rows for the auto-categorization audit…');
        $memoryCount = $this->merchantMemory->run($userMap);
        $this->info(sprintf('  %d demo merchant memories present', $memoryCount));

        $this->line('Seeding demo email-scan inbox slate (Gmail + Microsoft)…');
        $inboxCount = $this->emailScan->run($userMap);
        $this->info(sprintf('  %d demo inboxes present', $inboxCount));

        $this->line('Seeding demo recovery-code rows…');
        $recoveryCount = $this->recoveryCodes->run($userMap);
        $this->info(sprintf('  %d demo recovery codes present', $recoveryCount));

        $this->line('Seeding demo community merchant mappings…');
        $communityCount = $this->communityMappings->run($userMap);
        $this->info(sprintf('  %d demo community mappings present', $communityCount));

        $this->line('Seeding demo wizard-progress slate (mixed states)…');
        $wizardCount = $this->wizardProgress->run($userMap);
        $this->info(sprintf('  %d demo wizard-progress rows present', $wizardCount));

        $this->line('Seeding demo receipts + pending enrichment conflicts…');
        $fileImportCount = $this->receipts->run($userMap);
        $this->info(sprintf('  %d demo file-import rows present', $fileImportCount));

        $this->line('Seeding demo savings goals…');
        $goalCount = $this->goals->run($userMap);
        $this->info(sprintf('  %d demo goals present', $goalCount));

        // The pots seeder resolves each pot's goal link by name, so it has to run
        // after the goals seeder; earlier, every pot lands with a null goal.
        $this->line('Seeding demo savings pots + allocations…');
        $potCount = $this->pots->run($userMap);
        $this->info(sprintf('  %d demo pots present', $potCount));

        $this->line('Adopting the NL deduction corpus + tagging tax-relevant demo transactions…');
        $taxTagCount = $this->taxTags->run($userMap);
        $this->info(sprintf('  %d demo tax tags present', $taxTagCount));

        $this->line('Authoring demo categorization rules…');
        $ruleCount = $this->categorizationRules->run($userMap);
        $this->info(sprintf('  %d demo categorization rules present', $ruleCount));

        $this->line('Splitting demo transactions across categories…');
        $splitCount = $this->transactionSplits->run($userMap);
        $this->info(sprintf('  %d demo split legs present', $splitCount));

        $this->line('Saving demo report definitions…');
        $reportCount = $this->savedReports->run($userMap);
        $this->info(sprintf('  %d demo saved reports present', $reportCount));

        // Runs the real detectors, so it must follow every transaction and
        // split write rather than join the alert seeders above.
        $this->line('Running anomaly detection across the demo ledger…');
        $anomalyCount = $this->anomalyAlerts->run($userMap);
        $this->info(sprintf('  %d demo anomaly alerts detected', $anomalyCount));

        // Last: dispatches real trigger events over every row above, with
        // delivery suppressed.
        $this->line('Seeding demo notification inbox (all 8 types, mixed read/unread/dismissed/resolved/dead-link)…');
        $notificationCount = $this->notifications->run($userMap);
        $this->info(sprintf('  %d demo notifications present', $notificationCount));

        // Nothing above fired TransactionImported, so the listener's index is
        // empty until this rebuild.
        $this->line('Rebuilding the full-text search index over the demo ledger…');
        $this->call('search:reindex');

        $this->newLine();
        $this->info('Demo dataset is ready. Log in as demo-1 (password: demo-only).');

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
