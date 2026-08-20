<?php

declare(strict_types=1);

namespace Modules\Community\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Community\Internal\Corpus\CorpusLoader;
use Modules\Community\Internal\Corpus\CorpusYamlReader;
use Modules\Community\Internal\Http\Livewire\HelpOthersTriageButton;
use Modules\Community\Internal\Http\Livewire\MysteryMerchantsPage;
use Modules\Community\Internal\Http\Livewire\SharedListSettingsPanel;
use Modules\Community\Internal\Http\Livewire\SuggestMappingModal;
use Modules\Community\Internal\Listeners\SeedCommunityCorpus;
use Modules\Community\Internal\Services\GitHubCompareUrlBuilder;
use Modules\Community\Internal\Shell\NoOpShell;
use Modules\Community\Public\Actions\OpenExternalUrlAction;
use Modules\Community\Public\Services\ClassificationRuleProvider;
use Modules\Community\Public\Services\CommunityCorpusQuery;
use Modules\Community\Public\Services\CorpusPatternMatcher;
use Modules\Community\Public\Services\SupportResourceProvider;
use Modules\Core\Public\Events\UserInstalled;
use Modules\Core\Public\Support\LoadsModuleResources;
use Native\Desktop\Contracts\Shell as ShellContract;

final class CommunityServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../config/community.php', 'community');

        $this->app->singleton(CorpusYamlReader::class);
        $this->app->singleton(CorpusLoader::class);
        $this->app->singleton(CommunityCorpusQuery::class);
        $this->app->singleton(ClassificationRuleProvider::class);
        $this->app->singleton(CorpusPatternMatcher::class);
        $this->app->singleton(SupportResourceProvider::class);
        $this->app->singleton(GitHubCompareUrlBuilder::class);
        $this->app->singleton(OpenExternalUrlAction::class);

        if (! $this->app->bound(ShellContract::class)) {
            $this->app->singleton(ShellContract::class, NoOpShell::class);
        }
    }

    public function boot(Dispatcher $events, LivewireManager $livewire): void
    {
        $this->loadModuleResources('community');

        $events->listen(UserInstalled::class, SeedCommunityCorpus::class);

        // Force the NoOp fallback outside the live NativePHP runtime — see the
        // module architecture doc's "NativePHP Shell binding" section for why
        // this must be re-asserted in boot() rather than left to register()'s
        // `! bound()` guard.
        if (! (bool) $this->app->make('config')->get('nativephp-internal.running', false)) {
            $this->app->singleton(ShellContract::class, NoOpShell::class);
        }

        $livewire->component('community.suggest-mapping-modal', SuggestMappingModal::class);
        $livewire->component('community.mystery-merchants-page', MysteryMerchantsPage::class);
        $livewire->component('community.shared-list-settings-panel', SharedListSettingsPanel::class);
        $livewire->component('community.help-others-triage-button', HelpOthersTriageButton::class);
    }
}
