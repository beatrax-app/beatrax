<?php

declare(strict_types=1);

namespace Modules\Tax\Tests\Support;

use Livewire\Component;
use Modules\Tax\Public\Http\Livewire\Concerns\HandlesTaxTagging;

// Three components mount this trait, all of them another module's Internal.
// Hosting it here keeps the test about the trait's own guard rather than about
// whichever consumer it was found on, and spares the boundary pin a crossing.
final class TaxTaggingRefusalHost extends Component
{
    use HandlesTaxTagging;

    // The two properties below are #[Locked], which is the whole point of them:
    // the server builds the banner and opens the picker, and no template writes
    // either. A test exercising the handlers behind them therefore arms the
    // state the way the server does rather than posting it from the client.
    /**
     * @param  array{counterpartyId: int, counterpartyName: string, untaggedCount: int, taxYear?: int, categoryId?: int|null, note?: string|null}  $suggestion
     */
    public function armBatchSuggestion(array $suggestion): void
    {
        $this->batchSuggestion = $suggestion;
    }

    public function armPicker(int $transactionId): void
    {
        $this->taxPickerTxId = $transactionId;
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
