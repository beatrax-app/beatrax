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

/**
 * Global command-palette modal.
 *
 * Mounted once per base layout
 * (resources/views/layouts/app.blade.php and
 * Modules/DevMode/Resources/views/layouts/dev-shell.blade.php) so
 * any authenticated page can open the palette by dispatching the
 * `palette:open` Livewire event (the global Alpine x-data keybind
 * handler on <body> fires this on ⌘K / Ctrl+K).
 *
 * Renders the server-side JSON registry the client-side Fuse.js
 * factory consumes. The registry merges three sources:
 *
 *   - Navigation entries (every authenticated app view) — visible
 *     to every user.
 *   - Dev commands (SAFE-tier only; DESTRUCTIVE-tier deliberately
 *     EXCLUDED to prevent muscle-memory disasters) — visible to
 *     developers only. The filter applies at JSON-emit time (this
 *     method), never on the client.
 *   - App actions (named cross-app actions like "Open profile") —
 *     visible to every user.
 *
 * The non-developer's JSON literally does not contain the
 * `source: 'dev'` rows — tampering with the client-side JSON does
 * not bypass the filter.
 *
 * Recent shortcuts (per-user, 5 entries, 30-day TTL) live in cache
 * key `dev_mode.palette_recent.{userId}`. pickEntry() writes to
 * that key on every selection; the same key is read on mount() to
 * seed the Recent rail divider.
 *
 * Method-DI throughout (no facades, per the project's DI-only rule).
 */
final class CommandPaletteModal extends Component
{
    /**
     * Recent-shortcuts list seeded on `mount()` and refreshed on every
     * `pickEntry()` call. Each entry mirrors the registry row shape so
     * the Recent rail can render labels without a second lookup.
     *
     * @var list<array{id: string, label: string, icon: string, hint: string, source: string, url: ?string, handler: ?string, name: ?string, tier: ?string}>
     */
    public array $recent = [];

    /** Maximum number of Recent rows cached per user (per UI-SPEC). */
    public const RECENT_LIMIT = 5;

    /** Cache TTL for the Recent list: 30 days, in seconds. */
    public const RECENT_TTL_SECONDS = 30 * 86400;

    public function mount(CurrentUser $user, CacheRepository $cache): void
    {
        $this->recent = $this->loadRecent($user, $cache);
    }

    /**
     * Listen for the global `palette:open` Livewire event so any page
     * (sidebar ⌘K hint, sidebar Dev block "Open Dev Console" link, the
     * Alpine `<body>` keybind) can pop the modal without owning the
     * mounting logic. The Alpine `palette()` factory the modal's
     * Blade view registers handles the actual open transition; this
     * Livewire endpoint exists purely so the dispatch has a sink and
     * server-side state (Recent list seed) refreshes on demand.
     */
    #[On('palette:open')]
    public function open(CurrentUser $user, CacheRepository $cache): void
    {
        $this->recent = $this->loadRecent($user, $cache);
        $this->dispatch('palette:opened');
    }

    /**
     * Called by the client-side `palette:picked` Livewire dispatch
     * after the user selects an entry. Writes the entry to the
     * Recent cache (dedup + cap at RECENT_LIMIT, 30-day TTL).
     *
     * Navigation is handled client-side by the `palette()` Alpine
     * factory (window.location for `url`-shaped rows, Livewire
     * browser event for `handlerEvent`-shaped rows, `spawn-command`
     * for `dev`-source rows). This method exists solely to persist
     * the selection so the Recent rail rebuilds on the next open.
     *
     * @param  array{id?: mixed, label?: mixed, icon?: mixed, hint?: mixed, source?: mixed, url?: mixed, handler?: mixed, name?: mixed, tier?: mixed}  $entry
     */
    #[On('palette:picked')]
    public function pickEntry(array $entry, CurrentUser $user, CacheRepository $cache): void
    {
        $id = is_string($entry['id'] ?? null) ? $entry['id'] : '';
        if ($id === '') {
            return;
        }

        $row = [
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

        // Dedup: drop any prior occurrence so the new entry lands at
        // the head of the list. Cap at RECENT_LIMIT after the
        // prepend so the oldest evicts cleanly.
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
     * Build the merged JSON registry the client-side Fuse.js factory
     * consumes. Filters dev rows by `is_developer` at this layer —
     * non-developers receive ZERO `source: 'dev'` rows so the dev
     * command labels are never on the client — defense-in-depth on
     * top of the EnsureDeveloperMode middleware on the routes
     * themselves.
     *
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
            // Filter Dev-Console sub-route entries (`dev.` prefix) for
            // non-developers so the JSON never exposes those labels —
            // defense-in-depth on top of the EnsureDeveloperMode guard.
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
            // SAFE-tier only — DESTRUCTIVE stays reachable via the
            // Re-run affordance's triple-gate. `hasArgs` lets
            // palette.js choose direct-spawn vs. the arg-prompt modal
            // without an extra round trip.
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
     * Read the per-user Recent list out of cache. Returns an empty
     * array if the key is absent or the cached value is not a list
     * of the expected shape (defensive — the cache store is shared
     * with other features).
     *
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
            $rows[] = [
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

        return $rows;
    }

    private function recentCacheKey(int $userId): string
    {
        return 'dev_mode.palette_recent.'.$userId;
    }
}
