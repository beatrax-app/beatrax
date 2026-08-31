<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Livewire;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Enums\Duration;
use Modules\Core\Public\Services\DevConsoleBuildGate;
use Modules\Core\Public\Support\Lang;
use Modules\DevMode\Internal\Enums\CommandTier;
use Modules\DevMode\Internal\Enums\PaletteSource;
use Modules\DevMode\Public\Contracts\AppActionRegistry;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Contracts\NavigationRegistry;
use Modules\Search\Public\Contracts\SearchResultsProvider;

final class CommandPaletteModal extends Component
{
    /**
     * @var list<array{id: string, label: string, icon: string, hint: string, source: string, url: ?string, handler: ?string, name: ?string, tier: ?string}>
     */
    public array $recent = [];

    public const int RECENT_LIMIT = 5;

    public static function recentTtlSeconds(): int
    {
        return 30 * Duration::Day->seconds();
    }

    public function mount(CurrentUser $user, CacheRepository $cache): void
    {
        $this->recent = $this->loadRecent($user, $cache);
    }

    #[On('palette:open')]
    public function open(CurrentUser $user, CacheRepository $cache): void
    {
        $this->recent = $this->loadRecent($user, $cache);
        $this->dispatch('palette:opened');
    }

    // Alpine has already navigated by the time this fires; it only persists
    // the pick so the Recent rail rebuilds on the next open.
    /**
     * @param  array{id?: mixed, label?: mixed, icon?: mixed, hint?: mixed, source?: mixed, url?: mixed, handler?: mixed, name?: mixed, tier?: mixed}  $entry
     */
    #[On('palette:picked')]
    public function pickEntry(array $entry, CurrentUser $user, CacheRepository $cache): void
    {
        $id = is_string($entry['id'] ?? null) ? $entry['id'] : '';
        if ($id === '') {
            return;
        }

        $row = $this->normalizeRecentEntry($entry, $id);

        $existing = $this->loadRecent($user, $cache);
        $filtered = array_values(array_filter(
            $existing,
            static fn (array $r): bool => $r['id'] !== $id,
        ));
        array_unshift($filtered, $row);
        $capped = array_slice($filtered, 0, self::RECENT_LIMIT);

        $cache->put(
            $this->recentCacheKey($user->id()),
            $capped,
            self::recentTtlSeconds(),
        );

        $this->recent = $capped;
    }

    public function render(
        ViewFactory $views,
        CurrentUser $user,
        NavigationRegistry $nav,
        DevCommandRegistry $commands,
        AppActionRegistry $actions,
        DevConsoleBuildGate $build,
        ?SearchResultsProvider $searchProvider = null,
    ): View {
        return $views->make('dev::livewire.command-palette-modal', [
            'registry' => $this->buildRegistry($user, $nav, $commands, $actions, $build),
            'recent' => $this->recent,
            'searchAvailable' => $searchProvider !== null,
        ]);
    }

    /**
     * @return list<array{id: string, label: string, icon: string, hint: string, source: string, url: ?string, handler: ?string, name: ?string, tier: ?string, hasArgs: bool, keywords: list<string>}>
     */
    public function buildRegistry(
        CurrentUser $user,
        NavigationRegistry $nav,
        DevCommandRegistry $commands,
        AppActionRegistry $actions,
        DevConsoleBuildGate $build,
    ): array {
        $isDeveloper = $build->permits()
            && $user->isAuthenticated()
            && $user->user()->is_developer === true;

        return [
            ...$this->viewRows($nav, $isDeveloper),
            ...($isDeveloper ? $this->devCommandRows($commands) : []),
            ...$this->actionRows($actions),
        ];
    }

    /**
     * @return list<array{id: string, label: string, icon: string, hint: string, source: string, url: ?string, handler: ?string, name: ?string, tier: ?string, hasArgs: bool, keywords: list<string>}>
     */
    private function viewRows(NavigationRegistry $nav, bool $isDeveloper): array
    {
        $registry = [];

        foreach ($nav->all() as $entry) {
            // The rendered JSON reaches the client, so dev labels have to be
            // withheld here, not merely hidden by the route middleware.
            if (! $isDeveloper && str_starts_with($entry->id, 'dev.')) {
                continue;
            }

            $source = str_starts_with($entry->id, 'dev.') ? PaletteSource::DevView : PaletteSource::View;

            $registry[] = [
                'id' => $entry->id,
                'label' => $entry->label,
                'icon' => $entry->icon,
                'hint' => $entry->hint,
                'source' => $source->value,
                'sourceLabel' => $source->label(),
                'url' => $entry->url,
                'handler' => null,
                'name' => null,
                'tier' => null,
                'hasArgs' => false,
                'keywords' => $entry->keywords,
            ];
        }

        return $registry;
    }

    /**
     * @return list<array{id: string, label: string, icon: string, hint: string, source: string, url: ?string, handler: ?string, name: ?string, tier: ?string, hasArgs: bool, keywords: list<string>}>
     */
    private function devCommandRows(DevCommandRegistry $commands): array
    {
        $registry = [];

        // Safe tier only: destructive commands stay behind the Re-run
        // affordance's triple gate. `hasArgs` lets palette.js pick
        // direct-spawn or the arg prompt without a round trip.
        foreach ($commands->safe() as $spec) {
            $hasArgs = count($spec->argsSchema) > 0;
            $label = Lang::get($spec->labelKey);
            $registry[] = [
                'id' => 'dev.cmd.'.$spec->name,
                'label' => Lang::get('dev::palette.run_command', ['command' => $spec->name]),
                'icon' => '›_',
                'hint' => $label,
                'source' => PaletteSource::Dev->value,
                'sourceLabel' => PaletteSource::Dev->label(),
                'url' => null,
                'handler' => $hasArgs ? 'command-args:prompt' : 'spawn-command',
                'name' => $spec->name,
                'tier' => CommandTier::Safe->value,
                'hasArgs' => $hasArgs,
                'keywords' => [$label, $spec->descriptionKey === null ? '' : Lang::get($spec->descriptionKey)],
            ];
        }

        return $registry;
    }

    /**
     * @return list<array{id: string, label: string, icon: string, hint: string, source: string, url: ?string, handler: ?string, name: ?string, tier: ?string, hasArgs: bool, keywords: list<string>}>
     */
    private function actionRows(AppActionRegistry $actions): array
    {
        $registry = [];

        foreach ($actions->all() as $action) {
            $registry[] = [
                'id' => $action->id,
                'label' => Lang::get($action->labelKey),
                'icon' => $action->icon,
                'hint' => Lang::get($action->hintKey),
                'source' => PaletteSource::Action->value,
                'sourceLabel' => PaletteSource::Action->label(),
                'url' => $action->url,
                'handler' => $action->handlerEvent,
                'name' => null,
                'tier' => null,
                'hasArgs' => false,
                'keywords' => $action->keywords,
            ];
        }

        return $registry;
    }

    /**
     * @return list<array{id: string, label: string, icon: string, hint: string, source: string, url: ?string, handler: ?string, name: ?string, tier: ?string}>
     */
    private function loadRecent(CurrentUser $user, CacheRepository $cache): array
    {
        if (! $user->isAuthenticated()) {
            return [];
        }

        $raw = $cache->get($this->recentCacheKey($user->id()));
        if (! is_array($raw)) {
            return [];
        }

        $rows = [];
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $id = is_string($entry['id'] ?? null) ? $entry['id'] : null;
            if ($id === null || $id === '') {
                continue;
            }
            $rows[] = $this->normalizeRecentEntry($entry, $id);
        }

        return $rows;
    }

    /**
     * @param  array<array-key, mixed>  $entry
     * @return array{id: string, label: string, icon: string, hint: string, source: string, url: ?string, handler: ?string, name: ?string, tier: ?string}
     */
    private function normalizeRecentEntry(array $entry, string $id): array
    {
        return [
            'id' => $id,
            'label' => is_string($entry['label'] ?? null) ? $entry['label'] : $id,
            'icon' => is_string($entry['icon'] ?? null) ? $entry['icon'] : '',
            'hint' => is_string($entry['hint'] ?? null) ? $entry['hint'] : '',
            // A recent pick is replayed from client storage, so an unknown
            // source is a stale or edited entry rather than a new kind.
            'source' => (PaletteSource::tryFrom(is_string($entry['source'] ?? null) ? $entry['source'] : '') ?? PaletteSource::View)->value,
            'url' => is_string($entry['url'] ?? null) ? $entry['url'] : null,
            'handler' => is_string($entry['handler'] ?? null) ? $entry['handler'] : null,
            'name' => is_string($entry['name'] ?? null) ? $entry['name'] : null,
            'tier' => is_string($entry['tier'] ?? null) ? $entry['tier'] : null,
        ];
    }

    // The owner is the key's `:`-separated suffix, which is the one shape
    // UserScopedDataPurge sweeps. Under a `.` this row held the reader's last
    // five search queries for 30 days after their account was deleted.
    /**
     * @link ../../../../../.docs/features/auth/user-scoped-purge.md#four-things-the-schema-sweep-cannot-see
     */
    private function recentCacheKey(int $userId): string
    {
        return 'dev_mode.palette_recent:'.$userId;
    }
}
