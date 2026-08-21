<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Ledger\Models\ImportRun;

final class ImportResults extends Component
{
    public int $importRunId = 0;

    public function mount(int $id): void
    {
        $this->importRunId = $id;
    }

    public function render(ViewFactory $views, CurrentUser $currentUser): View
    {
        $user = $currentUser->user();

        /** @var ImportRun $importRun */
        $importRun = ImportRun::query()
            ->where('id', $this->importRunId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        return $views->make('import::livewire.import-results', [
            'importRun' => $importRun,
        ]);
    }
}
