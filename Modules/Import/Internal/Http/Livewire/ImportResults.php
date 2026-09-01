<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Enums\JobRunStatus;
use Modules\Import\Internal\Dto\ImportRowIssue;
use Modules\Import\Internal\Enums\ImportIssueKind;
use Modules\Import\Public\Enums\SyntheticSourceFormat;
use Modules\Ledger\Models\ImportRun;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @link ../../../../../.docs/features/import/architecture.md#chain-resolution-progress-on-the-results-page
 */
final class ImportResults extends Component
{
    // Locked because a Livewire property is client-mutable between requests,
    // and this one names the run every query below is scoped to. render()
    // re-checks ownership, so an unlocked foreign id would 404 rather than
    // disclose — but it would still be the client choosing the run.
    #[Locked]
    public int $importRunId = 0;

    public function mount(int $id): void
    {
        $this->importRunId = $id;
    }

    public function render(ViewFactory $views, CurrentUser $currentUser, DatabaseManager $db): View
    {
        $user = $currentUser->user();

        /** @var ImportRun $importRun */
        $importRun = ImportRun::query()
            ->where('id', $this->importRunId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        // A run whose source_format is synthetic never was an import: a demo
        // seed and a hand-typed cash entry both hang their rows off one for
        // want of anywhere else to put them. Reporting "Imported 0
        // transactions" over 95 of them describes an event that never happened.
        if (SyntheticSourceFormat::tryFrom($importRun->source_format) !== null) {
            throw new NotFoundHttpException('That import run is not an import.');
        }

        // The counts alone were the whole disclosure: expanding "show errors"
        // produced a definition of the word rather than the errors. What the
        // confirm recorded on the run is what this screen can still name.
        $issues = ImportRowIssue::listFromStored($importRun->row_issues);

        return $views->make('import::livewire.import-results', [
            'importRun' => $importRun,
            'chainResolutionStatus' => $this->chainResolutionStatus($db, $user->id),
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

    // Derived in render(), so the surface has a status on its first draw: the
    // wizard's version hung on a property only its own poll set, and so could
    // never draw at all. Never a failed_jobs.payload LIKE '%userId:N%' lookup,
    // whose id-prefix substring match reads another user's run.
    private function chainResolutionStatus(DatabaseManager $db, int $userId): ?JobRunStatus
    {
        $status = $db->connection()->table('chain_resolution_runs')
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->value('status');

        return is_scalar($status) ? JobRunStatus::tryFrom((string) $status) : null;
    }
}
