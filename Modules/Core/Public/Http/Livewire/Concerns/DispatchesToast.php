<?php

declare(strict_types=1);

namespace Modules\Core\Public\Http\Livewire\Concerns;

use Livewire\Component;

// The single seam every Livewire component uses to raise a toast, so the
// `toast` event name and its message/undo param shape live in one place
// rather than at each call site. toast() is the plain notice;
// toastWithUndo() carries the undo action + payload undo-able toasts send.
/**
 * @phpstan-require-extends Component
 */
trait DispatchesToast
{
    protected function toast(string $message): void
    {
        $this->dispatch('toast', message: $message);
    }

    protected function toastWithUndo(string $message, string $undoAction, mixed $undoPayload): void
    {
        $this->dispatch('toast', message: $message, undoAction: $undoAction, undoPayload: $undoPayload);
    }
}
