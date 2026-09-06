<?php

declare(strict_types=1);

namespace App\Support\SampleData;

use Closure;
use Illuminate\Contracts\Console\Kernel;
use Modules\Anomaly\Database\Seeders\Demo\DemoAnomalyAlertsSeeder;
use Modules\Auth\Database\Seeders\Demo\DemoRecoveryCodesSeeder;
use Modules\Budgets\Database\Seeders\Demo\DemoEnvelopeBudgetsSeeder;
use Modules\CashBook\Database\Seeders\Demo\DemoCashEntriesSeeder;
use Modules\Categorization\Database\Seeders\DefaultCategoryTreeSeeder;
use Modules\Categorization\Database\Seeders\Demo\DemoCategorizationRulesSeeder;
use Modules\Categorization\Database\Seeders\Demo\DemoMerchantMemorySeeder;
use Modules\Chains\Database\Seeders\Demo\DemoChainsSeeder;
use Modules\Community\Database\Seeders\Demo\DemoCommunityMappingsSeeder;
use Modules\Core\Database\Seeders\Demo\DemoSystemAlertsSeeder;
use Modules\Core\Database\Seeders\Demo\DemoUserPreferencesSeeder;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\SampleDataLoader;
use Modules\Core\Public\Enums\SampleDataScope;
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
use Modules\Ledger\Models\Account;
use Modules\Notifications\Database\Seeders\Demo\DemoNotificationsSeeder;
use Modules\Onboarding\Database\Seeders\Demo\DemoWizardProgressSeeder;
use Modules\Pots\Database\Seeders\Demo\DemoPotsSeeder;
use Modules\Receipts\Database\Seeders\Demo\DemoReceiptsSeeder;
use Modules\Recurring\Database\Seeders\Demo\DemoRecurringSeeder;
use Modules\Reports\Database\Seeders\Demo\DemoSavedReportsSeeder;
use Modules\Tax\Database\Seeders\Demo\DemoTaxTagsSeeder;
use RuntimeException;

// The order the sample dataset is built in, in one place, because both callers
// need the same one: `demo:seed` over invented accounts, and the in-application
// control over the reader's own. Two seeders write the install's own state
// rather than its ledger and are reached only by the first.
/**
 * @link ../../../.docs/features/core/architecture.md
 */
final readonly class SampleDatasetSeeder implements SampleDataLoader
{
    // The persona whose fixtures a single reader receives. Every seeder keys
    // its fixture table by persona name and skips a persona absent from the
    // map, so handing the reader in under this key gives them that persona's
    // ledger and nothing of the other's.
    public const string READER_PERSONA = 'demo-1';

    public function __construct(
        private CurrenciesSeeder $currencies,
        private DefaultCategoryTreeSeeder $categories,
        private DemoUserPreferencesSeeder $userPreferences,
        private DemoAccountsSeeder $accounts,
        private DemoTransactionsSeeder $transactions,
        private DemoCashEntriesSeeder $cashEntries,
        private DemoEnvelopeBudgetsSeeder $envelopeBudgets,
        private DemoCounterpartiesSeeder $counterparties,
        private DemoMerchantAliasesSeeder $merchantAliases,
        private DemoTransferPairsSeeder $transferPairs,
        private DemoChainsSeeder $chains,
        private DemoRecurringSeeder $recurring,
        private DemoDriftAlertsSeeder $driftAlerts,
        private DemoForecastSeeder $forecast,
        private DemoSystemAlertsSeeder $systemAlerts,
        private DemoMerchantMemorySeeder $merchantMemory,
        private DemoEmailScanSeeder $emailScan,
        private DemoRecoveryCodesSeeder $recoveryCodes,
        private DemoCommunityMappingsSeeder $communityMappings,
        private DemoWizardProgressSeeder $wizardProgress,
        private DemoReceiptsSeeder $receipts,
        private DemoGoalsSeeder $goals,
        private DemoPotsSeeder $pots,
        private DemoTaxTagsSeeder $taxTags,
        private DemoCategorizationRulesSeeder $categorizationRules,
        private DemoTransactionSplitsSeeder $transactionSplits,
        private DemoSavedReportsSeeder $savedReports,
        private DemoAnomalyAlertsSeeder $anomalyAlerts,
        private DemoNotificationsSeeder $notifications,
        private Kernel $console,
    ) {}

    /**
     * @return array<string, int>
     */
    public function loadFor(int $userId): array
    {
        $user = User::query()->find($userId);

        if (! $user instanceof User) {
            throw new RuntimeException("Cannot load sample data for user {$userId}: no such account.");
        }

        return $this->seed([self::READER_PERSONA => $user], SampleDataScope::LedgerOnly);
    }

    /**
     * @param  array<string, User>  $userMap
     * @param  (Closure(string, int): void)|null  $announce  called per step with its key and count
     * @return array<string, int>
     */
    public function seed(array $userMap, SampleDataScope $scope, ?Closure $announce = null): array
    {
        $this->currencies->run();
        $this->categories->run();

        $counts = ['user_preferences' => $this->userPreferences->run($userMap)];

        $accountMap = $this->accounts->run($userMap);
        $counts['accounts'] = array_sum(array_map('count', $accountMap));

        $counts += $this->ledger($userMap, $accountMap);
        $counts += $this->derived($userMap);
        $counts += $this->installState($userMap, $scope);
        $counts += $this->products($userMap);

        // Nothing above fired TransactionImported, so the listener's index is
        // empty until this rebuild.
        $this->console->call('search:reindex');

        if ($announce !== null) {
            foreach ($counts as $step => $count) {
                $announce($step, $count);
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, User>  $userMap
     * @param  array<string, array<string, Account>>  $accountMap
     * @return array<string, int>
     */
    private function ledger(array $userMap, array $accountMap): array
    {
        $counts = ['transactions' => $this->transactions->run($userMap, $accountMap)];

        // Before the budget assignments, which are tuned against the spend the
        // ledger holds, and before the pots, whose funding is bounded by what
        // the cash account is left holding.
        $counts['cash_entries'] = $this->cashEntries->run($userMap);
        $counts['envelope_assignments'] = $this->envelopeBudgets->run($userMap);
        $counts['counterparties'] = $this->counterparties->run($userMap);
        $counts['merchant_aliases'] = $this->merchantAliases->run($userMap);
        $counts['transfer_pair_legs'] = $this->transferPairs->run($userMap, $accountMap);
        $counts['chain_links'] = $this->chains->run($userMap, $accountMap);

        return $counts;
    }

    /**
     * @param  array<string, User>  $userMap
     * @return array<string, int>
     */
    private function derived(array $userMap): array
    {
        return [
            'recurring_series' => $this->recurring->run($userMap),
            'drift_alerts' => $this->driftAlerts->run($userMap),
            'forecast_scenarios' => $this->forecast->run($userMap),
            'system_alerts' => $this->systemAlerts->run($userMap),
            'merchant_memories' => $this->merchantMemory->run($userMap),
            'inboxes' => $this->emailScan->run($userMap),
        ];
    }

    // Recovery codes and wizard progress are the install's own state, not its
    // ledger. Run over a real account they replace the reader's recovery codes
    // and reopen their onboarding, so the reader path does not reach them.
    /**
     * @param  array<string, User>  $userMap
     * @return array<string, int>
     */
    private function installState(array $userMap, SampleDataScope $scope): array
    {
        if (! $scope->reachesInstallState()) {
            return [];
        }

        return [
            'recovery_codes' => $this->recoveryCodes->run($userMap),
            'wizard_progress' => $this->wizardProgress->run($userMap),
        ];
    }

    /**
     * @param  array<string, User>  $userMap
     * @return array<string, int>
     */
    private function products(array $userMap): array
    {
        $counts = ['community_mappings' => $this->communityMappings->run($userMap)];
        $counts['file_imports'] = $this->receipts->run($userMap);
        $counts['goals'] = $this->goals->run($userMap);

        // The pots seeder resolves each pot's goal link by name, so it has to
        // run after the goals seeder; earlier, every pot lands with a null goal.
        $counts['pots'] = $this->pots->run($userMap);
        $counts['tax_tags'] = $this->taxTags->run($userMap);
        $counts['categorization_rules'] = $this->categorizationRules->run($userMap);
        $counts['split_legs'] = $this->transactionSplits->run($userMap);
        $counts['saved_reports'] = $this->savedReports->run($userMap);

        // Runs the real detectors, so it must follow every transaction and
        // split write rather than join the alert seeders above.
        $counts['anomaly_alerts'] = $this->anomalyAlerts->run($userMap);

        // Last: dispatches real trigger events over every row above, with
        // delivery suppressed.
        $counts['notifications'] = $this->notifications->run($userMap);

        return $counts;
    }
}
