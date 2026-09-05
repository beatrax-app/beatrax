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

    public function render(): string
    {
        return '<div></div>';
    }
}
