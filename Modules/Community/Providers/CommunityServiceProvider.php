<?php

declare(strict_types=1);

namespace Modules\Community\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Community\Internal\Corpus\CorpusLoader;
use Modules\Community\Internal\Corpus\CorpusYamlReader;
use Modules\Community\Internal\Http\Livewire\MysteryMerchantsPage;
use Modules\Community\Internal\Http\Livewire\SharedListSettingsPanel;
use Modules\Community\Internal\Http\Livewire\SuggestMappingModal;
use Modules\Community\Internal\Listeners\SeedCommunityCorpus;
use Modules\Community\Internal\Services\GitHubCompareUrlBuilder;
use Modules\Community\Internal\Shell\UnopenedUrl;
use Modules\Community\Public\Actions\OpenExternalUrlAction;
use Modules\Community\Public\Services\ClassificationRuleProvider;
use Modules\Community\Public\Services\CommunityCorpusQuery;
use Modules\Community\Public\Services\CorpusPatternMatcher;
use Modules\Community\Public\Services\SupportResourceProvider;
use Modules\Core\Public\Contracts\ExternalUrlOpener;
use Modules\Core\Public\Events\UserInstalled;
use Modules\Core\Public\Support\LoadsModuleResources;

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

        // Last binding wins and this one never overwrites, so a platform
        // provider may claim the opener in either order. The re-assert this
        // replaces existed because NativePHP bound the desktop Shell itself at
        // register time; nothing but Beatrax binds this contract.
        if (! $this->app->bound(ExternalUrlOpener::class)) {
            $this->app->singleton(ExternalUrlOpener::class, UnopenedUrl::class);
        }
    }

    public function boot(Dispatcher $events, LivewireManager $livewire): void
    {
        $this->loadModuleResources('community');

        $events->listen(UserInstalled::class, SeedCommunityCorpus::class);

        $livewire->component('community.suggest-mapping-modal', SuggestMappingModal::class);
        $livewire->component('community.mystery-merchants-page', MysteryMerchantsPage::class);
        $livewire->component('community.shared-list-settings-panel', SharedListSettingsPanel::class);
    }
}
