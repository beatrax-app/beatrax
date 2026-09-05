<?php

declare(strict_types=1);

namespace Modules\Categorization\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Categorization\Database\Seeders\DefaultCategorizationRuleSeeder;
use Modules\Categorization\Internal\Actions\AssignCategory;
use Modules\Categorization\Internal\Http\Livewire\RuleFormModal;
use Modules\Categorization\Internal\Http\Livewire\RulesPage;
use Modules\Categorization\Internal\Http\Livewire\TriageInbox;
use Modules\Categorization\Internal\Listeners\DeactivateRulesOnReferentDelete;
use Modules\Categorization\Internal\Listeners\MerchantMemoryWriter;
use Modules\Categorization\Internal\Listeners\SeedDefaultCategorizationRules;
use Modules\Categorization\Internal\Listeners\SeedDefaultCategoryTree;
use Modules\Categorization\Internal\Pipeline\ApplyAutoCategoryStage;
use Modules\Categorization\Internal\Services\RuleEvaluator;
use Modules\Categorization\Public\Actions\CreateCategorizationRule;
use Modules\Categorization\Public\Actions\DeleteCategorizationRule;
use Modules\Categorization\Public\Actions\UpdateCategorizationRule;
use Modules\Categorization\Public\Contracts\AppliesAutoCategory;
use Modules\Categorization\Public\Contracts\AssignsCategory;
use Modules\Categorization\Public\Events\TransactionCategorized;
use Modules\Categorization\Public\Http\Livewire\CategorizationProvenancePanel;
use Modules\Categorization\Public\Http\Livewire\InlineCategoryPicker;
use Modules\Categorization\Public\Services\CategoryOptionsQuery;
use Modules\Categorization\Public\Services\MerchantMemoryQuery;
use Modules\Core\Public\Events\UserInstalled;
use Modules\Core\Public\Support\LoadsModuleResources;
use Modules\Sync\Public\Events\EntityMutated;
use Modules\Sync\Public\Events\PeerRowsApplied;

final class CategorizationServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    public function register(): void
    {
        $this->app->bind(AssignsCategory::class, AssignCategory::class);
        $this->app->bind(AppliesAutoCategory::class, ApplyAutoCategoryStage::class);
        $this->app->scoped(CategoryOptionsQuery::class);
        $this->app->singleton(RuleEvaluator::class);
        $this->app->singleton(MerchantMemoryQuery::class);
        $this->app->singleton(CreateCategorizationRule::class);
        $this->app->singleton(UpdateCategorizationRule::class);
        $this->app->bind(DeleteCategorizationRule::class);
        $this->app->singleton(DefaultCategorizationRuleSeeder::class);
        $this->app->singleton(DeactivateRulesOnReferentDelete::class);
    }

    public function boot(Dispatcher $events, LivewireManager $livewire): void
    {
        $this->loadModuleResources('categorization');
        $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');

        $events->listen(UserInstalled::class, SeedDefaultCategoryTree::class);
        $events->listen(UserInstalled::class, SeedDefaultCategorizationRules::class);
        $events->listen(TransactionCategorized::class, [MerchantMemoryWriter::class, 'handle']);

        // rule_actions.payload embeds category_id/counterparty_id as
        // opaque JSON with no FK, so this listens on the framework's own
        // `eloquent.deleting: {FQCN}` wildcard event name (a plain string
        // — no Ledger/Counterparties model class is imported here).
        $events->listen('eloquent.deleting: Modules\Ledger\Models\Category', [DeactivateRulesOnReferentDelete::class, 'handleCategoryDeleting']);
        $events->listen('eloquent.deleting: Modules\Counterparties\Models\Counterparty', [DeactivateRulesOnReferentDelete::class, 'handleCounterpartyDeleting']);

        // A query-builder delete fires no model event, so the arm above cannot
        // be the only one: a writer that deletes a counterparty announces it,
        // and this is what that announcement reaches.
        $events->listen(EntityMutated::class, [DeactivateRulesOnReferentDelete::class, 'handleCounterpartyPruned']);

        // Neither arm above fires for a referent a PEER deleted: the merge
        // writes through the query builder, and it raises no EntityMutated
        // because the capture listener would send the peer's row back. Rules
        // are device-local, so this is the only side that can deactivate them.
        $events->listen(PeerRowsApplied::class, [DeactivateRulesOnReferentDelete::class, 'handlePeerRowsApplied']);

        $livewire->component('categorization.triage-inbox', TriageInbox::class);
        $livewire->component('categorization.inline-category-picker', InlineCategoryPicker::class);
        $livewire->component('categorization.rules-page', RulesPage::class);
        $livewire->component('categorization.rule-form-modal', RuleFormModal::class);
        $livewire->component('categorization.categorization-provenance-panel', CategorizationProvenancePanel::class);
    }
}
