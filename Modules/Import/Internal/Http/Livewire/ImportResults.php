<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Import\Internal\Dto\ImportRowIssue;
use Modules\Import\Internal\Enums\ImportIssueKind;
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

        // The counts alone were the whole disclosure: expanding "show errors"
        // produced a definition of the word rather than the errors. What the
        // confirm recorded on the run is what this screen can still name.
        $issues = ImportRowIssue::listFromStored($importRun->row_issues);

        return $views->make('import::livewire.import-results', [
            'importRun' => $importRun,
            'errorIssues' => array_values(array_filter(
                $issues,
                static fn (ImportRowIssue $issue): bool => $issue->kind !== ImportIssueKind::Duplicate,
            )),
            'duplicateIssues' => array_values(array_filter(
                $issues,
                static fn (ImportRowIssue $issue): bool => $issue->kind === ImportIssueKind::Duplicate,
            )),
        ]);
    }
}
