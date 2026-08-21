<?php

declare(strict_types=1);

namespace Modules\Receipts\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Receipts\Public\Actions\ApplyReceiptConflictResolution;
use Modules\Receipts\Public\Enums\ReceiptConflictChoice;
use Modules\Receipts\Public\Services\ReceiptConflictQuery;

// Surfaces the latest pending conflict on mount, which is the whole delivery
// path: the `receipt-conflict-detected` listener this used to carry could
// never fire, because conflicts are recorded by queued jobs and a worker
// reaches no browser. Actions stay scoped by ApplyReceiptConflictResolution.
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

    public function useReceipt(CurrentUser $currentUser, ApplyReceiptConflictResolution $resolve): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }
        $resolve($currentUser->user(), ReceiptConflictChoice::PreferReceipt->value);
        $this->visible = false;
    }

    public function keepStatement(CurrentUser $currentUser, ApplyReceiptConflictResolution $resolve): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }
        $resolve($currentUser->user(), ReceiptConflictChoice::PreferFirstWrite->value);
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
