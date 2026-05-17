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

/**
 * Livewire SFC for the first-conflict receipt toast (D-707). Listens
 * for the `receipt-conflict-detected` local Livewire event AND falls
 * back to pulling the latest pending conflict on mount so a backfill
 * job that produced a held conflict in a prior request still surfaces
 * the choice on the next page render.
 *
 * Services arrive as parameters on the relevant action / render
 * methods — constructor injection is banned on Livewire components by
 * the strict-rules plugin. Mirrors the InlineCategoryPicker +
 * BackfillWindowModal pattern.
 *
 * Cross-user safety: the SFC reads the current user via `CurrentUser`
 * and scopes every action through ApplyReceiptConflictResolution,
 * which only ever touches rows whose `user_id` matches the current
 * user. The toast NEVER receives a foreign conflict via the local
 * Livewire bridge because the bridge dispatch only fires on the
 * current request's import — and the mount() fallback is itself
 * user-scoped via ReceiptConflictQuery.
 *
 * UI-SPEC contract:
 *  - Heading: "Receipt and statement disagree."
 *  - Body templated with {field} / {receipt_value} / {csv_value}
 *  - Primary: "Use receipt" -> prefer_receipt
 *  - Secondary: "Keep statement" -> prefer_first_write
 *  - NO auto-dismiss (persists until user acts)
 *  - Re-fires on every conflict until users.receipt_conflict_resolution
 *    !== 'unset'.
 */
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

    /**
     * Local Livewire-event bridge from ApplyEnrichments-dispatched
     * `ReceiptConflictDetected` — when both producer + consumer live
     * in the same request lifecycle (wizard-driven import), the toast
     * surfaces immediately without a re-render.
     *
     * The userId is checked against the current authenticated user as
     * a defensive guard — local Livewire events are scoped to the
     * current request, so this should always pass; surfacing a
     * mismatch as a no-op makes any future regression fail-safe.
     */
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
