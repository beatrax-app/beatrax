<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Mobile\Internal\Sync\InitialSyncPuller;
use Modules\Mobile\Internal\Sync\PeerLanAddress;
use Modules\Mobile\Internal\Sync\SetupStep;
use Modules\Mobile\Internal\Sync\SyncBlockedReason;
use Modules\Mobile\Internal\Sync\SyncPhase;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @link ../../../../../.docs/features/mobile/mobile-initial-sync-gate.md
 */
final class SetupProgressScreen extends Component
{
    public int $recordsApplied = 0;

    public ?int $recordsExpected = null;

    public int $percent = 0;

    // All three are derived from the puller on every mount and every tick, so
    // nothing on the wire may name them: a client-set `complete` walked past
    // the gate this screen exists to hold.
    #[Locked]
    public SyncPhase $phase = SyncPhase::Pending;

    // Null while the pull is simply working. Rendered as copy, so a stall
    // reads as a state rather than a frozen screen.
    #[Locked]
    public ?SyncBlockedReason $blocked = null;

    #[Locked]
    public SetupStep $step = SetupStep::Connect;

    // Picks the "Resuming setup" headline over the fresh-start one, and never
    // reverts once resumed.
    public bool $isResuming = false;

    public function mount(CurrentUser $currentUser, InitialSyncPuller $puller): void
    {
        $progress = $puller->progress($currentUser->id());

        $this->applyProgress($progress);

        $this->isResuming = $this->recordsApplied > 0 || $this->phase !== SyncPhase::Pending;
    }

    public function poll(
        CurrentUser $currentUser,
        InitialSyncPuller $puller,
        Session $session,
        UrlGenerator $urls,
        PeerLanAddress $peerAddress,
        LoggerInterface $logger,
    ): void {
        if ($this->phase === SyncPhase::Complete) {
            $this->redirect($urls->route('mobile.setup.done'), navigate: false);

            return;
        }

        // Without the desktop's address only the relay leg runs, and that
        // drains a mailbox without applying rows: 0 of 0 forever. Recalled
        // rather than located, because a browse costs its whole timeout and
        // the pull below already runs one when nothing is remembered.
        try {
            $address = $peerAddress->recall($currentUser->id());

            $progress = $puller->pull(
                $currentUser->id(),
                $session,
                $address['host'] ?? null,
                $address['port'] ?? null,
            );

            $this->applyProgress($progress);
        } catch (Throwable $e) {
            // This tick IS the screen: letting the throw out answered 500,
            // which Livewire drops, so the view kept its last frame and
            // looked alive while nothing ran again.
            $this->blocked = SyncBlockedReason::Retrying;

            $logger->warning('SetupProgressScreen: initial-sync tick failed — retrying on the next poll.', [
                'user_id' => $currentUser->id(),
                ...SafeExceptionContext::describe($e),
            ]);
        }

        // The confirmation, not the dashboard: dropping straight into a
        // populated app answered neither question the wait raises — did it
        // work, and what happens from now on.
        if ($this->phase === SyncPhase::Complete) {
            $this->redirect($urls->route('mobile.setup.done'), navigate: false);
        }
    }

    // The app-lock allow-list exempts this route so a wire:poll tick is never
    // interrupted by the PIN screen. Nothing then redirects a reader whose lock
    // engaged anyway, and the blocked copy named a door with no handle: the
    // screen sat on "unlock to continue" through a relaunch, with no control.
    public function render(ViewFactory $views, UrlGenerator $urls): View
    {
        $view = $views->make('mobile::livewire.setup-progress-screen', [
            'lockUrl' => $urls->route('mobile.lock'),
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.lock', ['title' => Lang::get('mobile::setup.page_title').' · Beatrax']);

        return $view;
    }

    /**
     * @param  array{records_applied: int, records_expected: ?int, percent: int, phase: SyncPhase, blocked: ?SyncBlockedReason}  $progress
     */
    private function applyProgress(array $progress): void
    {
        $this->recordsApplied = $progress['records_applied'];
        $this->recordsExpected = $progress['records_expected'];
        $this->phase = $progress['phase'];
        $this->blocked = $progress['blocked'];
        $this->step = $this->phase === SyncPhase::Complete ? SetupStep::Rebuild : SetupStep::forBlocked($this->blocked);
        $this->percent = $this->stepPercent($progress['percent']);
    }

    // The CURRENT step, not the whole ceremony: a ceremony-wide number sat at
    // 100% through the rebuild, because the transfer it measured was done.
    private function stepPercent(int $transferPercent): int
    {
        // The cursor's expected count only ever equals what has already been
        // applied, so treating it as a total renders a full bar the instant
        // the first row lands.
        $hasRealTotal = $this->recordsExpected !== null && $this->recordsExpected > $this->recordsApplied;

        return match (true) {
            $this->phase === SyncPhase::Complete => 100,
            $this->step === SetupStep::Transfer && $hasRealTotal => $transferPercent,
            default => 0,
        };
    }

    /**
     * @return list<SetupStep>
     */
    public function steps(): array
    {
        return SetupStep::ordered();
    }
}
