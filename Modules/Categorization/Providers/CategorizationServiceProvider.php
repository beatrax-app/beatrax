<?php

declare(strict_types=1);

namespace Modules\Categorization\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Categorization\Database\Seeders\DefaultCategorizationRuleSeeder;
use Modules\Categorization\Internal\Http\Livewire\CategorizationProvenancePanel;
use Modules\Categorization\Internal\Http\Livewire\CorrectionDivergenceToast;
use Modules\Categorization\Internal\Http\Livewire\InlineCategoryPicker;
use Modules\Categorization\Internal\Http\Livewire\RuleFormModal;
use Modules\Categorization\Internal\Http\Livewire\RulesPage;
use Modules\Categorization\Internal\Http\Livewire\TriageInbox;
use Modules\Categorization\Internal\Listeners\DeactivateRulesOnReferentDelete;
use Modules\Categorization\Internal\Listeners\MerchantMemoryWriter;
use Modules\Categorization\Internal\Listeners\SeedDefaultCategorizationRules;
use Modules\Categorization\Internal\Listeners\SeedDefaultCategoryTree;
use Modules\Categorization\Internal\Pipeline\ApplyAutoCategoryStage;
use Modules\Categorization\Internal\Services\RuleEvaluator;
use Modules\Categorization\Public\Actions\AssignCategory;
use Modules\Categorization\Public\Actions\CreateCategorizationRule;
use Modules\Categorization\Public\Actions\DeleteCategorizationRule;
use Modules\Categorization\Public\Actions\UpdateCategorizationRule;
use Modules\Categorization\Public\Contracts\AppliesAutoCategory;
use Modules\Categorization\Public\Contracts\AssignsCategory;
use Modules\Categorization\Public\Events\TransactionCategorized;
use Modules\Categorization\Public\Services\CategorizationRuleQuery;
use Modules\Categorization\Public\Services\CategoryOptionsQuery;
use Modules\Categorization\Public\Services\MerchantMemoryQuery;
use Modules\Categorization\Public\Services\UncategorizedTriageQuery;
use Modules\Core\Public\Events\UserInstalled;

/**
 * Wires the Categorization module:
 *
 * - binds the `AssignsCategory` public contract to its `AssignCategory`
 *   default implementation (routes writes through Ledger's
 *   `UpdatesTransactionCategory`).
 * - registers `UncategorizedTriageQuery` as a stateless singleton.
 * - listens for `UserInstalled` and runs two seeders in order:
 *   `SeedDefaultCategoryTree` (global default categories,
 *   `user_id = NULL`) followed by `SeedDefaultCategorizationRules`
 *   (per-user universal-merchant rule set keyed off the just-seeded
 *   categories). The category tree precedes the rule seeder so every
 *   rule's category slug resolves at insert time.
 * - registers the two Livewire components rendered on `/uncategorized`
 *   and inside the `/transactions` rows.
 * - listens on the Ledger `Category` and Counterparties `Counterparty`
 *   models' `eloquent.deleting` wildcard events and deactivates any
 *   rule whose `rule_actions.payload` references the deleted row
 *   (D-03's app-level referential-integrity replacement for the FK
 *   cascade a JSON payload cannot carry — see
 *   `DeactivateRulesOnReferentDelete`).
 * - loads migrations, routes, and views.
 */
final class CategorizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AssignsCategory::class, AssignCategory::class);
        $this->app->bind(AppliesAutoCategory::class, ApplyAutoCategoryStage::class);
        $this->app->singleton(UncategorizedTriageQuery::class);
        $this->app->singleton(CategoryOptionsQuery::class);
        $this->app->singleton(RuleEvaluator::class);
        $this->app->singleton(ApplyAutoCategoryStage::class);
        $this->app->singleton(CategorizationRuleQuery::class);
        $this->app->singleton(MerchantMemoryQuery::class);
        $this->app->singleton(CreateCategorizationRule::class);
        $this->app->singleton(UpdateCategorizationRule::class);
        $this->app->singleton(DeleteCategorizationRule::class);
        // MerchantMemoryWriter is stateless; binding it as a singleton
        // avoids a fresh container resolution per TransactionCategorized
        // dispatch and matches the binding pattern other listeners use.
        $this->app->singleton(MerchantMemoryWriter::class);
        $this->app->singleton(DefaultCategorizationRuleSeeder::class);
        $this->app->singleton(DeactivateRulesOnReferentDelete::class);
    }

    public function boot(Dispatcher $events, LivewireManager $livewire): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'categorization');

        $events->listen(UserInstalled::class, SeedDefaultCategoryTree::class);
        $events->listen(UserInstalled::class, SeedDefaultCategorizationRules::class);
        $events->listen(TransactionCategorized::class, [MerchantMemoryWriter::class, 'handle']);

        // D-03 referential-integrity guard (RESEARCH.md Pitfall 2 /
        // T-13.4-09): rule_actions.payload embeds category_id/
        // counterparty_id as opaque JSON with no FK. Neither the Ledger
        // nor the Counterparties module exposes a Public delete action or
        // delete event today, so this listens on the framework's own
        // `eloquent.deleting: {FQCN}` wildcard event name (a plain
        // string — no Ledger/Counterparties model class is imported here)
        // rather than reaching into either module's internals.
        $events->listen('eloquent.deleting: Modules\Ledger\Models\Category', [DeactivateRulesOnReferentDelete::class, 'handleCategoryDeleting']);
        $events->listen('eloquent.deleting: Modules\Counterparties\Models\Counterparty', [DeactivateRulesOnReferentDelete::class, 'handleCounterpartyDeleting']);

        $livewire->component('categorization.triage-inbox', TriageInbox::class);
        $livewire->component('categorization.inline-category-picker', InlineCategoryPicker::class);
        $livewire->component('categorization.rules-page', RulesPage::class);
        $livewire->component('categorization.rule-form-modal', RuleFormModal::class);
        $livewire->component('categorization.categorization-provenance-panel', CategorizationProvenancePanel::class);
        $livewire->component('categorization.correction-divergence-toast', CorrectionDivergenceToast::class);
    }
}
