<?php

declare(strict_types=1);

namespace Modules\Receipts\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Import\Public\Enums\EnrichmentConflictField;
use Modules\Ledger\Public\ValueObjects\Money;
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

    // The conflict the rendered copy quotes, and the only one either button
    // answers. Locked because it decides which row a press writes to.
    #[Locked]
    public ?int $conflictId = null;

    public ?int $transactionId = null;

    public ?string $field = null;

    public ?string $receiptValue = null;

    public ?string $csvValue = null;

    // The two figures are quoted in a sentence, so each needs the money it is
    // denominated in: the stored one in the transaction's, the receipt's in
    // whatever code the receipt itself named.
    public string $storedCurrency = '';

    public string $receiptCurrency = '';

    public function mount(CurrentUser $currentUser, ReceiptConflictQuery $query): void
    {
        $this->showLatest($currentUser, $query);
    }

    public function useReceipt(CurrentUser $currentUser, ReceiptConflictQuery $query, ApplyReceiptConflictResolution $resolve): void
    {
        $this->answer($currentUser, $query, $resolve, ReceiptConflictChoice::PreferReceipt);
    }

    public function keepStatement(CurrentUser $currentUser, ReceiptConflictQuery $query, ApplyReceiptConflictResolution $resolve): void
    {
        $this->answer($currentUser, $query, $resolve, ReceiptConflictChoice::PreferFirstWrite);
    }

    // One press answers the conflict on screen and then offers the next one:
    // dismissing outright would hide conflicts the reader never saw behind a
    // toast that only comes back on the next full render.
    private function answer(
        CurrentUser $currentUser,
        ReceiptConflictQuery $query,
        ApplyReceiptConflictResolution $resolve,
        ReceiptConflictChoice $choice,
    ): void {
        if (! $currentUser->isAuthenticated() || $this->conflictId === null) {
            return;
        }

        $resolve($currentUser->user(), $choice, $this->conflictId);
        $this->showLatest($currentUser, $query);
    }

    private function showLatest(CurrentUser $currentUser, ReceiptConflictQuery $query): void
    {
        $latest = $currentUser->isAuthenticated() ? $query->latestForUser($currentUser->user()) : null;

        if ($latest === null) {
            $this->visible = false;
            $this->conflictId = null;
            $this->transactionId = null;
            $this->field = null;
            $this->receiptValue = null;
            $this->csvValue = null;
            $this->storedCurrency = '';
            $this->receiptCurrency = '';

            return;
        }

        $this->visible = true;
        $this->conflictId = $latest['conflictId'];
        $this->transactionId = $latest['transactionId'];
        $this->field = $latest['field'];
        $this->receiptValue = $latest['incomingValue'];
        $this->csvValue = $latest['storedValue'];
        $this->storedCurrency = $latest['storedCurrency'];
        $this->receiptCurrency = $latest['incomingCurrency'];
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('receipts::livewire.receipt-conflict-toast', [
            'visible' => $this->visible,
            'field' => $this->field,
            // Named apart from the two properties they are built from:
            // Livewire merges public properties over a render()'s own data, so
            // a key that repeats a property name never reaches the view.
            'quotedReceipt' => $this->quoted($this->receiptValue, $this->receiptCurrency),
            'quotedStatement' => $this->quoted($this->csvValue, $this->storedCurrency),
        ]);
    }

    // An amount conflict holds the two `amount_minor` integers, and the toast
    // quoted them as they stood: "-3199" offered to the reader as money, at no
    // stated currency and at a scale a yen does not have.
    private function quoted(?string $value, string $currencyCode): ?string
    {
        if ($value === null || $this->field !== EnrichmentConflictField::AmountMinor->value || ! is_numeric($value)) {
            return $value;
        }

        return Money::tryOfMinor((int) $value, $currencyCode)?->format() ?? $value;
    }
}
