<?php

declare(strict_types=1);

namespace Modules\Receipts\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Step component for the /imports wizard "Email file" arm.
 *
 * For Wave 1 the wizard works through the existing UploadWizard
 * component — the SUPPORTED_FORMATS, validation, and submit flow
 * accept the new `email-file` issuer + `eml`/`mbox` leaf formats
 * without a separate page. This SFC is registered with Livewire
 * as a thin shell so that future deeper wizard customisation
 * (e.g. a per-file matcher-preview before upload) has a stable
 * component to live on without requiring a wizard architecture
 * rewrite.
 */
final class WizardEmailFileStep extends Component
{
    public function render(ViewFactory $views): View
    {
        return $views->make('receipts::livewire.wizard-email-file-step');
    }
}
