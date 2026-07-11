<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Mobile\Internal\Sync\InitialSyncPuller;

/**
 * The blocking, resumable, full-screen initial-sync setup gate (D-03/D-04,
 * R5, MOBILE-01/MOBILE-02) — the post-pairing landing page (`/mobile/setup`,
 * routed + Livewire-registered by the Plan 01 `MobileServiceProvider`; this
 * plan does NOT edit the provider or routes).
 *
 * `mount()` reads `InitialSyncPuller::progress()` — the DURABLE
 * `mobile_sync_progress` cursor, never a default-0 Livewire property — so a
 * cold-started process (an app-kill mid-pull, exactly what routinely
 * happens on mobile OSes, RESEARCH Pattern 3 / Pitfall 5) renders at the
 * TRUE resumed percent on its very first paint. `poll()` (driven by
 * `wire:poll` in the view) then actively drives the pull forward one
 * bounded step at a time via `InitialSyncPuller::pull()` until
 * `phase === 'complete'`, then redirects into the app.
 *
 * Genuinely blocking (D-03): no cancel/dismiss/back/skip control anywhere
 * in this component or its view — a finance app must never let the user
 * skip past a half-populated balance.
 *
 * Constructor-free Livewire component (phpstan-strict-rules forbids a
 * constructor on a `Component` subclass) — collaborators arrive as
 * mount()/poll()/render() method-DI parameters.
 */
final class SetupProgressScreen extends Component
{
    public int $recordsApplied = 0;

    public ?int $recordsExpected = null;

    public int $percent = 0;

    public string $phase = 'pending';

    /**
     * True the moment the FIRST render already reflects prior progress
     * (a non-zero applied count, or any phase past 'pending') — drives the
     * "Resuming setup…" vs "Setting up this device…" headline. UI-SPEC
     * Copywriting: never re-show the fresh-start copy once resumed.
     */
    public bool $isResuming = false;

    public function mount(CurrentUser $currentUser, InitialSyncPuller $puller): void
    {
        $progress = $puller->progress($currentUser->id());

        $this->applyProgress($progress);

        $this->isResuming = $this->recordsApplied > 0 || $this->phase !== 'pending';
    }

    /**
     * `wire:poll` tick — drives the pull forward by ONE bounded step and
     * redirects into the app the moment `phase` reaches `complete`.
     *
     * Peer discovery (LAN host/port resolution) is out of this plan's
     * scope (mirrors 15-05-PLAN.md's own "peer discovery... out of this
     * plan's scope" note) — this tick relies on whatever transport leg
     * (LAN, if a future plan supplies host/port, or the configured relay)
     * `MobileSyncTriggerService::syncOnce()` can already reach.
     */
    public function poll(
        CurrentUser $currentUser,
        InitialSyncPuller $puller,
        Session $session,
        UrlGenerator $urls,
    ): void {
        if ($this->phase === 'complete') {
            $this->redirect($urls->route('dashboard'), navigate: false);

            return;
        }

        $progress = $puller->pull($currentUser->id(), $session);

        $this->applyProgress($progress);

        if ($this->phase === 'complete') {
            $this->redirect($urls->route('dashboard'), navigate: false);
        }
    }

    public function render(ViewFactory $views): View
    {
        $view = $views->make('mobile::livewire.setup-progress-screen');

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.lock', ['title' => 'Setting up… · beatrax']);

        return $view;
    }

    /**
     * @param  array{records_applied: int, records_expected: ?int, percent: int, phase: string}  $progress
     */
    private function applyProgress(array $progress): void
    {
        $this->recordsApplied = $progress['records_applied'];
        $this->recordsExpected = $progress['records_expected'];
        $this->percent = $progress['percent'];
        $this->phase = $progress['phase'];
    }
}
