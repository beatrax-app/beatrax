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
    ): void {
        $this->errorMessage = '';

        if ($this->inboxId === null) {
            $this->errorMessage = Lang::get('core::errors.no_longer_here');

            return;
        }

        $user = $currentUser->user();
        $row = $db->connection()
            ->table('inboxes')
            ->where('id', $this->inboxId)
            ->where('user_id', $user->id)
            ->first(['id']);

        // The modal outlives the row behind it: disconnect the mailbox in
        // another tab and this submit still names its id. The message is the
        // answer, not a 404 page over the top of the wizard.
        if ($row === null) {
            $this->errorMessage = Lang::get('core::errors.no_longer_here');

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

    public function render(ViewFactory $views): View
    {
        return $views->make('email-scan::livewire.backfill-window-modal', [
            'inboxId' => $this->inboxId,
            'months' => $this->months,
            'errorMessage' => $this->errorMessage,
        ]);
    }
}
