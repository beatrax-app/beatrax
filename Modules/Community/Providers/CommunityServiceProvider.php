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
use Native\Desktop\Contracts\Shell as ShellContract;

/**
 * Wires the Community module:
 *
 *  - registers the corpus loader, the community-tier read-only query,
 *    and the GitHub Compare URL builder as singletons.
 *  - binds the OpenExternalUrlAction so the SuggestMappingModal can
 *    DI it through its `submit()` method.
 *  - binds `Native\Desktop\Contracts\Shell` to the in-module NoOpShell
 *    fallback if no other module has already bound the contract.
 *    NativePHP's NativeServiceProvider binds the real implementation
 *    inside the desktop runtime; this binding only takes effect when
 *    the bundle runs outside that runtime (local dev mode, CI tests).
 *  - listens for `UserInstalled` and runs the SeedCommunityCorpus
 *    listener, mirroring SeedDefaultCategoryTree's idempotent posture.
 *  - loads the migration that creates `community_merchant_mappings`,
 *    the module's routes file, and the `community::` Blade view
 *    namespace.
 */
final class CommunityServiceProvider extends ServiceProvider
{
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
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $routesPath = __DIR__.'/../Routes/web.php';
        if (file_exists($routesPath)) {
            $this->loadRoutesFrom($routesPath);
        }

        $viewsPath = __DIR__.'/../Resources/views';
        if (is_dir($viewsPath)) {
            $this->loadViewsFrom($viewsPath, 'community');
        }

        $events->listen(UserInstalled::class, SeedCommunityCorpus::class);

        // NativePHP's NativeServiceProvider unconditionally binds the real
        // Shell during registration (packageRegistered), so the register()
        // `! bound()` fallback above never wins when the package is installed.
        // Outside the live desktop runtime (php artisan serve, CI, tests) that
        // real implementation POSTs to the Electron bridge on localhost:4000,
        // which isn't running — every openExternal() throws a
        // ConnectionException (cURL error 7). Force the NoOp fallback whenever
        // we are NOT inside the NativePHP runtime. Done in boot() so it wins
        // regardless of provider registration order; nothing resolves Shell
        // during boot (it is only used at request/click time).
        if (! (bool) $this->app->make('config')->get('nativephp-internal.running', false)) {
            $this->app->singleton(ShellContract::class, NoOpShell::class);
        }

        $livewire->component('community.suggest-mapping-modal', SuggestMappingModal::class);
        $livewire->component('community.mystery-merchants-page', MysteryMerchantsPage::class);
        $livewire->component('community.shared-list-settings-panel', SharedListSettingsPanel::class);
        $livewire->component('community.help-others-triage-button', HelpOthersTriageButton::class);
    }
}
