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

/**
 * Flux modal SFC for the backfill window picker.
 *
 * Auto-opens once after the OAuth callback redirect via the
 * `backfill-window:open` Livewire event (the InboxesPage mount()
 * hook reads the `open_backfill_modal` session flash and dispatches
 * the event). Re-opens via the inline [Edit] link on every inbox
 * row in the connected-inboxes table.
 *
 * Body shape: a single 1-12 month slider (default 3) plus a
 * Confirm button. Submit clamps the value to [1, 12] defensively,
 * persists the new backfill_window_months on the inbox row, and
 * dispatches BackfillInboxJob through the injected Bus contract.
 *
 * Cross-user 404 invariant: every read + write against the
 * inboxes row scopes to the current user via `where('user_id',
 * $user->id)`. A foreign id never returns 404-able existence
 * information; the controller layer translates the null into a
 * Symfony NotFoundHttpException so the response is identical to a
 * missing-inbox case.
 *
 * Service collaborators arrive as parameters on action methods +
 * the render() method — constructor injection is banned on
 * Livewire components by the strict-rules plugin.
 */
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

        $this->dispatch('modal-hide', name: 'backfill-window-'.$this->inboxId);
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
