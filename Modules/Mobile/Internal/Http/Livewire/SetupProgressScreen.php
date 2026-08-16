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
use Modules\Mobile\Internal\Sync\PeerLanAddress;
use Modules\Mobile\Internal\Sync\SetupStep;
use Modules\Mobile\Internal\Sync\SyncBlockedReason;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @link ../../../../../.docs/features/mobile/architecture.md
 */
final class SetupProgressScreen extends Component
{
    public int $recordsApplied = 0;

    public ?int $recordsExpected = null;

    public int $percent = 0;

    public string $phase = 'pending';

    // What the pull is waiting on, or null while it is simply working. Shown
    // as copy so a stall reads as a state rather than a frozen screen.
    public ?SyncBlockedReason $blocked = null;

    // The stage the device is on now. Everything before it is finished,
    // everything after is still to come — the screen shows all of them so a
    // long stage reads as "step 3 of 4", not as a hang.
    public SetupStep $step = SetupStep::Connect;

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
        PeerLanAddress $peerAddress,
        LoggerInterface $logger,
    ): void {
        if ($this->phase === 'complete') {
            $this->redirect($urls->route('mobile.setup.done'), navigate: false);

            return;
        }

        // Hand the puller the desktop's address. Without it only the relay
        // leg runs, and that drains a mailbox without applying rows — the
        // screen then reports 0 of 0 forever.
        try {
            $progress = $puller->pull(
                $currentUser->id(),
                $session,
                $peerAddress->host(),
                $peerAddress->port(),
            );

            $this->applyProgress($progress);
        } catch (Throwable $e) {
            // This tick IS the screen. Letting the throw out answered 500,
            // which Livewire drops on the floor, so the view kept its last
            // frame and looked alive while nothing ran again. Report it and
            // let the next tick retry.
            $this->blocked = SyncBlockedReason::Retrying;

            $logger->warning('SetupProgressScreen: initial-sync tick failed — retrying on the next poll.', [
                'user_id' => $currentUser->id(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        // Hands off to the confirmation rather than the dashboard. Dropping
        // straight into a populated app gave no answer to the two questions
        // the wait itself raises: did it work, and what happens from now on.
        if ($this->phase === 'complete') {
            $this->redirect($urls->route('mobile.setup.done'), navigate: false);
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
     * @param  array{records_applied: int, records_expected: ?int, percent: int, phase: string, blocked: ?SyncBlockedReason}  $progress
     */
    private function applyProgress(array $progress): void
    {
        $this->recordsApplied = $progress['records_applied'];
        $this->recordsExpected = $progress['records_expected'];
        $this->phase = $progress['phase'];
        $this->blocked = $progress['blocked'];
        $this->step = $this->phase === 'complete' ? SetupStep::Rebuild : SetupStep::forBlocked($this->blocked);
        $this->percent = $this->stepPercent($progress['percent']);
    }

    // How far the CURRENT step has got, not how far the whole ceremony has.
    // A ceremony-wide number sat at 100% through the entire rebuild, because
    // the transfer it measured was already finished.
    private function stepPercent(int $transferPercent): int
    {
        // A determinate bar needs a total the device actually knows. The
        // cursor's expected count only ever equals what has already been
        // applied, so treating it as a total renders 100% the instant the
        // first row lands — a full bar over a transfer that has barely begun.
        $hasRealTotal = $this->recordsExpected !== null && $this->recordsExpected > $this->recordsApplied;

        return match (true) {
            $this->phase === 'complete' => 100,
            $this->step === SetupStep::Transfer && $hasRealTotal => $transferPercent,
            // Everything else reports indeterminate: honest about not knowing
            // rather than inventing a number that only moves at the end.
            default => 0,
        };
    }

    // Livewire cannot call enum statics from the view, so the ordered list
    // is handed over ready to render.
    /**
     * @return list<SetupStep>
     */
    public function steps(): array
    {
        return SetupStep::ordered();
    }
}
