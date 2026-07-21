<?php

declare(strict_types=1);

namespace Modules\Receipts\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;

// A thin shell registered with Livewire so future per-file
// matcher-preview customisation has a stable component to live on —
// the shared UploadWizard already handles the email-file arm's
// validation and submit.
final class WizardEmailFileStep extends Component
{
    public function render(ViewFactory $views): View
    {
        return $views->make('receipts::livewire.wizard-email-file-step');
    }
}
