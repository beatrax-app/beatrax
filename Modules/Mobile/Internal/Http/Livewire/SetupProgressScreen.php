<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Sync\InitialSyncPuller;

/**
 * @link ../../../../../.docs/features/mobile/architecture.md
 */
final class SetupProgressScreen extends Component
{
    public int $recordsApplied = 0;

    public ?int $recordsExpected = null;

    public int $percent = 0;

    public string $phase = 'pending';

    // True the moment the first render already reflects prior progress -
    // drives the "Resuming setup..." vs "Setting up this device..."
    // headline, never re-showing the fresh-start copy once resumed.
    public bool $isResuming = false;

    public function mount(CurrentUser $currentUser, InitialSyncPuller $puller): void
    {
        $progress = $puller->progress($currentUser->id());

        $this->applyProgress($progress);

        $this->isResuming = $this->recordsApplied > 0 || $this->phase !== 'pending';
    }

    // wire:poll tick - drives the pull forward by one bounded step and
    // redirects into the app the moment phase reaches complete. Peer
    // discovery (LAN host/port resolution) is out of scope here; this
    // tick relies on whatever transport leg syncOnce() can already reach.
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
        $view->extends('layouts.lock', ['title' => Lang::get('mobile::setup.page_title').' · Beatrax']);

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
