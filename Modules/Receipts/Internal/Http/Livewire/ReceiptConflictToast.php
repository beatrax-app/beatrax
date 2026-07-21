<?php

declare(strict_types=1);

namespace Modules\Receipts\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Receipts\Public\Actions\ApplyReceiptConflictResolution;
use Modules\Receipts\Public\Services\ReceiptConflictQuery;

// Listens for the local receipt-conflict-detected Livewire event AND
// falls back to the latest pending conflict on mount, so a conflict
// held during a background job still surfaces on the next render.
// Every action is scoped by ApplyReceiptConflictResolution's user_id.
final class ReceiptConflictToast extends Component
{
    public bool $visible = false;

    public ?int $transactionId = null;

    public ?string $field = null;

    public ?string $receiptValue = null;

    public ?string $csvValue = null;

    public function mount(CurrentUser $currentUser, ReceiptConflictQuery $query): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }
        $latest = $query->latestForUser($currentUser->user());
        if ($latest === null) {
            return;
        }

        $this->visible = true;
        $this->transactionId = $latest['transactionId'];
        $this->field = $latest['field'];
        $this->receiptValue = $latest['incomingValue'];
        $this->csvValue = $latest['storedValue'];
    }

    #[On('receipt-conflict-detected')]
    public function handleConflictDetected(
        CurrentUser $currentUser,
        int $transactionId,
        int $userId,
        string $field,
        ?string $receiptValue,
        ?string $csvValue,
    ): void {
        if (! $currentUser->isAuthenticated() || $currentUser->id() !== $userId) {
            return;
        }

        $this->visible = true;
        $this->transactionId = $transactionId;
        $this->field = $field;
        $this->receiptValue = $receiptValue;
        $this->csvValue = $csvValue;
    }

    public function useReceipt(CurrentUser $currentUser, ApplyReceiptConflictResolution $resolve): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }
        $resolve($currentUser->user(), 'prefer_receipt');
        $this->visible = false;
    }

    public function keepStatement(CurrentUser $currentUser, ApplyReceiptConflictResolution $resolve): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }
        $resolve($currentUser->user(), 'prefer_first_write');
        $this->visible = false;
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('receipts::livewire.receipt-conflict-toast', [
            'visible' => $this->visible,
            'field' => $this->field,
            'receiptValue' => $this->receiptValue,
            'csvValue' => $this->csvValue,
        ]);
    }
}
