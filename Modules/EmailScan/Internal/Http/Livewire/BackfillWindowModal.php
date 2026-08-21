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
use Modules\EmailScan\Internal\Jobs\BackfillInboxJob;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
        if ($this->inboxId === null) {
            throw new NotFoundHttpException('Inbox not found.');
        }

        $user = $currentUser->user();
        $row = $db->connection()
            ->table('inboxes')
            ->where('id', $this->inboxId)
            ->where('user_id', $user->id)
            ->first(['id']);

        if ($row === null) {
            throw new NotFoundHttpException('Inbox not found.');
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
