<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Database\Seeders\Demo\DemoRecoveryCodesSeeder;
use Modules\Budgets\Database\Seeders\Demo\DemoEnvelopeBudgetsSeeder;
use Modules\Categorization\Database\Seeders\DefaultCategoryTreeSeeder;
use Modules\Categorization\Database\Seeders\Demo\DemoMerchantMemorySeeder;
use Modules\Chains\Database\Seeders\Demo\DemoChainsSeeder;
use Modules\Community\Database\Seeders\Demo\DemoCommunityMappingsSeeder;
use Modules\Core\Database\Seeders\Demo\DemoSystemAlertsSeeder;
use Modules\Core\Database\Seeders\Demo\DemoUserPreferencesSeeder;
use Modules\Counterparties\Database\Seeders\Demo\DemoCounterpartiesSeeder;
use Modules\DriftAlerts\Database\Seeders\Demo\DemoDriftAlertsSeeder;
use Modules\EmailScan\Database\Seeders\Demo\DemoEmailScanSeeder;
use Modules\Forecasting\Database\Seeders\Demo\DemoForecastSeeder;
use Modules\Import\Database\Seeders\Demo\DemoMerchantAliasesSeeder;
use Modules\Ledger\Database\Seeders\CurrenciesSeeder;
use Modules\Ledger\Database\Seeders\Demo\DemoAccountsSeeder;
use Modules\Ledger\Database\Seeders\Demo\DemoTransactionsSeeder;
use Modules\Ledger\Database\Seeders\Demo\DemoTransferPairsSeeder;
use Modules\Ledger\Database\Seeders\Demo\DemoUsersSeeder;
use Modules\Notifications\Database\Seeders\Demo\DemoNotificationsSeeder;
use Modules\Onboarding\Database\Seeders\Demo\DemoWizardProgressSeeder;
use Modules\Receipts\Database\Seeders\Demo\DemoReceiptsSeeder;
use Modules\Recurring\Database\Seeders\Demo\DemoRecurringSeeder;

// Developer-only tool standing up a realistic-looking demo install
// across every seeder in the constructor list below. Every transaction
// belongs to an import_runs row stamped source_format='demo', which
// --reset walks to scope the wipe to demo data only, never real users.
final class DemoSeedCommand extends Command
{
    /** @var string */
    protected $signature = 'demo:seed {--reset : Tear down existing demo data before reseeding}';

    /** @var string */
    protected $description = 'Stand up a realistic-looking demo dataset (2 users, 5 accounts, ~165 transactions, chains, recurring, forecast, drift, system + scan + receipts + recovery + wizard data). Developer-only.';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CurrenciesSeeder $currencies,
        private readonly DefaultCategoryTreeSeeder $categories,
        private readonly DemoUsersSeeder $users,
        private readonly DemoAccountsSeeder $accounts,
        private readonly DemoUserPreferencesSeeder $userPreferences,
        private readonly DemoTransactionsSeeder $transactions,
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
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('reset') === true) {
            $this->resetDemoData();
        }

        // Reference-data prerequisites the demo dataset assumes are
        // already populated by the production install flow; calling them
        // explicitly here makes the demo seeder safe to run against a
        // freshly-migrated database that never ran beatrax:install.
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

        $this->line('Seeding demo transactions (~165 rows across 90 days)…');
        $txCount = $this->transactions->run($userMap, $accountMap);
        $this->info(sprintf('  %d demo transactions present', $txCount));

        $this->line('Activating envelope budgeting + seeding current-period assignments…');
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

        // Runs last — references series/budget-category/account/drift-alert
        // rows seeded above, dispatching the real trigger events with
        // delivery suppressed so no OS notification ever fires here.
        $this->line('Seeding demo notification inbox (all 8 types, mixed read/unread/dismissed/resolved/dead-link)…');
        $notificationCount = $this->notifications->run($userMap);
        $this->info(sprintf('  %d demo notifications present', $notificationCount));

        $this->newLine();
        $this->info('Demo dataset is ready. Log in as demo-1@beatrax.local (password: demo-only).');

        return self::SUCCESS;
    }

    // Deletion order honours the FK dependency graph; SQLite's ON DELETE
    // CASCADE collapses the rest. Bounded to demo data via the seed_key
    // marker on system_alerts and the demo:// eml_path prefix on
    // file_imports, even against a populated production DB.
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

        // The composite UNIQUE index on transactions(user_id, fingerprint)
        // means a stale row with the same fingerprint would block a
        // re-seed; wipe transactions explicitly first so the order is
        // observable in the logs rather than relying on the FK cascade.
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
            // These tables carry a user_id column but aren't necessarily
            // owned by an ImportRun; wiping them explicitly (rather than
            // relying only on the users-cascade below) lets a partial
            // reset still finish.
            $connection->table('envelope_moves')
                ->whereIn('user_id', $demoUserIds)
                ->delete();
            $connection->table('envelope_assignments')
                ->whereIn('user_id', $demoUserIds)
                ->delete();
            $connection->table('envelope_settings')
                ->whereIn('user_id', $demoUserIds)
                ->delete();
            $connection->table('drift_alert_transitions')
                ->whereIn('user_id', $demoUserIds)
                ->delete();
            $connection->table('drift_alerts')
                ->whereIn('user_id', $demoUserIds)
                ->delete();
            $connection->table('recurring_series_transitions')
                ->whereIn('user_id', $demoUserIds)
                ->delete();
            $connection->table('recurring_series_occurrences')
                ->whereIn('user_id', $demoUserIds)
                ->delete();
            $connection->table('forecast_shortfall_windows')
                ->whereIn('user_id', $demoUserIds)
                ->delete();
            $connection->table('forecast_scenario_mutations')
                ->whereIn('user_id', $demoUserIds)
                ->delete();
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
            $connection->table('merchant_aliases')
                ->whereIn('user_id', $demoUserIds)
                ->delete();
            $connection->table('merchant_memories')
                ->whereIn('user_id', $demoUserIds)
                ->delete();
            $connection->table('merchants')
                ->whereIn('user_id', $demoUserIds)
                ->delete();
            $connection->table('system_alerts')
                ->whereIn('user_id', $demoUserIds)
                ->delete();
            $connection->table('user_preferences')
                ->whereIn('user_id', $demoUserIds)
                ->delete();
            $connection->table('user_recovery_codes')
                ->whereIn('user_id', $demoUserIds)
                ->delete();
            $connection->table('community_merchant_mappings')
                ->whereIn('user_id', $demoUserIds)
                ->delete();
            $connection->table('wizard_progress')
                ->whereIn('user_id', $demoUserIds)
                ->delete();
            $connection->table('pending_enrichment_conflicts')
                ->whereIn('user_id', $demoUserIds)
                ->delete();
            $connection->table('file_imports')
                ->whereIn('user_id', $demoUserIds)
                ->delete();
            $connection->table('known_senders')
                ->whereIn('user_id', $demoUserIds)
                ->delete();
            // EmailScan child tables cascade from inboxes; wipe child
            // rows first so a partial-reset state with orphan
            // inbox_messages still cleans up.
            $connection->table('discovered_senders')
                ->whereIn('user_id', $demoUserIds)
                ->delete();
            $connection->table('inbox_messages')
                ->whereIn('user_id', $demoUserIds)
                ->delete();
            $connection->table('inbox_scan_state')
                ->whereIn('user_id', $demoUserIds)
                ->delete();
            $connection->table('inboxes')
                ->whereIn('user_id', $demoUserIds)
                ->delete();
            $connection->table('oauth_secrets')
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
