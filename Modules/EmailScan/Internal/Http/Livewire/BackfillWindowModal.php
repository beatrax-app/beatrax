<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Http\Livewire;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\EmailScan\Internal\Jobs\BackfillInboxJob;
use Modules\EmailScan\Public\Enums\InboxScanStatus;
use Modules\EmailScan\Public\Services\InboxQuery;

final class BackfillWindowModal extends Component
{
    public ?int $inboxId = null;

    public int $months = 3;

    public string $errorMessage = '';

    #[On('backfill-window:open')]
    public function open(int $inboxId, ?int $currentWindow = null): void
    {
        $this->inboxId = $inboxId;
        $this->months = $currentWindow ?? 3;
        $this->errorMessage = '';
    }

    public function submit(
        CurrentUser $currentUser,
        DatabaseManager $db,
        Dispatcher $bus,
        Clock $clock,
        InboxQuery $inboxQuery,
    ): void {
        $this->errorMessage = '';

        if ($this->inboxId === null) {
            $this->errorMessage = Lang::get('core::errors.no_longer_here');

            return;
        }

        $user = $currentUser->user();
        $health = $inboxQuery->findForUser($this->inboxId, $user);

        // The modal outlives the row behind it: disconnect the mailbox in
        // another tab and this submit still names its id. The message is the
        // answer, not a 404 page over the top of the wizard.
        if ($health === null) {
            $this->errorMessage = Lang::get('core::errors.no_longer_here');

            return;
        }

        // The [Edit] link that opens this modal carries no disabled condition,
        // so Start backfill is reachable in states the job refuses on its first
        // transition — where it returns and the closed modal is all that is said.
        $refusal = self::refusalFor($health->status);
        if ($refusal !== null) {
            $this->errorMessage = Lang::get($refusal);

            return;
        }

        $clamped = max(1, min(12, $this->months));

        $db->connection()
            ->table('inboxes')
            ->where('id', $this->inboxId)
            ->where('user_id', $user->id)
            ->update([
                'backfill_window_months' => $clamped,
                'updated_at' => $clock->now()->toDateTimeString(),
            ]);

        $bus->dispatch(new BackfillInboxJob($this->inboxId, $clamped));

        $this->dispatch('modal-close', name: 'backfill-window-'.$this->inboxId);
    }

    // The same refusal InboxesPage::scanNow makes before dispatching, because a
    // backfill is a scan: two of these states already have one running, and the
    // third is the one the opening transition cannot leave until a Reconnect.
    private static function refusalFor(string $status): ?string
    {
        return match (true) {
            in_array($status, [
                InboxScanStatus::Backfilling->value,
                InboxScanStatus::Scanning->value,
            ], strict: true) => 'email-scan::inboxes.toast.scan_in_progress',
            $status === InboxScanStatus::NeedsReauth->value => 'email-scan::inboxes.toast.reconnect_first',
            default => null,
        };
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('email-scan::livewire.backfill-window-modal', [
            'inboxId' => $this->inboxId,
            'months' => $this->months,
            'errorMessage' => $this->errorMessage,
        ]);
    }
}
