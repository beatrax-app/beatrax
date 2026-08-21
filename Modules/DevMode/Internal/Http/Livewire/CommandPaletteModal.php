<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Livewire;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
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

    public const RECENT_LIMIT = 5;

    public const RECENT_TTL_SECONDS = 30 * 86400;

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
            self::RECENT_TTL_SECONDS,
        );

        $this->recent = $capped;
    }

    public function render(
        ViewFactory $views,
        CurrentUser $user,
        NavigationRegistry $nav,
        DevCommandRegistry $commands,
        AppActionRegistry $actions,
        ?SearchResultsProvider $searchProvider = null,
    ): View {
        return $views->make('dev::livewire.command-palette-modal', [
            'registry' => $this->buildRegistry($user, $nav, $commands, $actions),
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
    ): array {
        $registry = [];

        $isDeveloper = $user->isAuthenticated() && $user->user()->is_developer === true;

        foreach ($nav->all() as $entry) {
            // The rendered JSON reaches the client, so dev labels have to be
            // withheld here, not merely hidden by the route middleware.
            if (! $isDeveloper && str_starts_with($entry->id, 'dev.')) {
                continue;
            }

            $registry[] = [
                'id' => $entry->id,
                'label' => $entry->label,
                'icon' => $entry->icon,
                'hint' => $entry->hint,
                'source' => str_starts_with($entry->id, 'dev.') ? 'dev-view' : 'view',
                'url' => $entry->url,
                'handler' => null,
                'name' => null,
                'tier' => null,
                'hasArgs' => false,
                'keywords' => $entry->keywords,
            ];
        }

        if ($isDeveloper) {
            // Safe tier only: destructive commands stay behind the Re-run
            // affordance's triple gate. `hasArgs` lets palette.js pick
            // direct-spawn or the arg prompt without a round trip.
            foreach ($commands->safe() as $spec) {
                $hasArgs = count($spec->argsSchema) > 0;
                $registry[] = [
                    'id' => 'dev.cmd.'.$spec->name,
                    'label' => 'Run '.$spec->name,
                    'icon' => '›_',
                    'hint' => $spec->label,
                    'source' => 'dev',
                    'url' => null,
                    'handler' => $hasArgs ? 'command-args:prompt' : 'spawn-command',
                    'name' => $spec->name,
                    'tier' => 'safe',
                    'hasArgs' => $hasArgs,
                    'keywords' => [$spec->label, $spec->description ?? ''],
                ];
            }
        }

        foreach ($actions->all() as $action) {
            $registry[] = [
                'id' => $action->id,
                'label' => $action->label,
                'icon' => $action->icon,
                'hint' => $action->hint,
                'source' => 'action',
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
            'source' => is_string($entry['source'] ?? null) ? $entry['source'] : 'view',
            'url' => is_string($entry['url'] ?? null) ? $entry['url'] : null,
            'handler' => is_string($entry['handler'] ?? null) ? $entry['handler'] : null,
            'name' => is_string($entry['name'] ?? null) ? $entry['name'] : null,
            'tier' => is_string($entry['tier'] ?? null) ? $entry['tier'] : null,
        ];
    }

    private function recentCacheKey(int $userId): string
    {
        return 'dev_mode.palette_recent.'.$userId;
    }
}
