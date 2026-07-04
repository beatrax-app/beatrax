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
use Modules\Onboarding\Database\Seeders\Demo\DemoWizardProgressSeeder;
use Modules\Receipts\Database\Seeders\Demo\DemoReceiptsSeeder;
use Modules\Recurring\Database\Seeders\Demo\DemoRecurringSeeder;

/**
 * Orchestrates the demo-dataset pipeline behind the `demo:seed`
 * artisan command. The command is a developer-only tool — it stands
 * up two demo users, five accounts, ~165 transactions across the
 * recent 90-day window, four pre-resolved funding chains plus two
 * candidate hint chains, six recurring detections covering every
 * lifecycle state, two forecast scenarios (Base + What-If) with a
 * mutation and a shortfall window, a representative drift-alert
 * dataset, system-alert banners for every kind, a Gmail + Microsoft
 * inbox-scanning slate, recovery codes, community mappings, wizard
 * progress in mixed states, receipts + a pending enrichment conflict,
 * a cross-account transfer pair, and merchant memory / alias rows.
 * It is the canonical path to a realistic-looking install for
 * screenshot capture, onboarding shake-out, and dev-experience parity.
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
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('reset') === true) {
            $this->resetDemoData();
        }

        // Reference-data prerequisites the demo dataset assumes are
        // already populated by the production install flow. Calling
        // them explicitly here makes the demo seeder safe to run
        // against a freshly-migrated database that has never been
        // through `php artisan beatrax:install` — every seeder is
        // idempotent so re-running over a populated install is a
        // no-op for these rows.
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

        $this->newLine();
        $this->info('Demo dataset is ready. Log in as demo-1@beatrax.local (password: demo-only).');

        return self::SUCCESS;
    }

    /**
     * Wipe every row the demo seeder has previously created. The query
     * builder is used directly so the deletion order honours the FK
     * dependency graph and SQLite's `ON DELETE CASCADE` collapses the
     * trailing deletions for us. Tables that cascade from
     * `users.id` are deleted last via the users wipe; tables that
     * cascade from per-row owners (drift_alerts → drift_alert_transitions,
     * forecast_scenarios → mutations + shortfall windows,
     * inboxes → scan_state + messages + discovered_senders) are
     * collapsed by the cascade DDL.
     *
     * The wipe is bounded to demo data exclusively. The `seed_key`
     * metadata marker on system_alerts plus the `demo://` eml_path
     * prefix on file_imports keep the wipe scoped even when the demo
     * seeder runs against a populated production DB.
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
            //
            // Order matters for tables whose FK chains stack:
            // drift_alert_transitions → drift_alerts → recurring_series_occurrences
            // → recurring_series; forecast_scenario_mutations +
            // forecast_shortfall_windows → forecast_scenarios.
            // SQLite's cascade DDL handles them automatically, but we
            // explicitly mirror the parent-first order for readability.
            // Envelope budgeting tables (Phase 13.2). All cascade from users,
            // but wipe explicitly so a partial reset still finishes the job.
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
