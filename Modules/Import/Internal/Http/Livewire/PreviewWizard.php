<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Actions\DiscardImport;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Contracts\NamesAccounts;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Exceptions\PreviewExpiredException;
use Modules\Ledger\Models\ImportRun;

/**
 * Step 2 of the wizard. Renders the preview table (with NEW / DUPLICATE /
 * ERROR per row), prompts inline for any unfamiliar IBANs, and exposes
 * `confirm` / `discard` actions.
 *
 * Lookups always scope to the authenticated user (CurrentUser) so a forged
 * importRunId from another user produces a ModelNotFoundException via
 * `firstOrFail` rather than exposing the other user's import run.
 */
final class PreviewWizard extends Component
{
    public int $importRunId = 0;

    public string $accountName = '';

    public bool $previewExpired = false;

    public function mount(int $id): void
    {
        $this->importRunId = $id;
    }

    public function nameAccount(
        string $iban,
        string $name,
        NamesAccounts $namer,
        RunsImports $importer,
        CurrentUser $currentUser,
    ): void {
        $user = $currentUser->user();
        ($namer)($iban, $name, $user);

        /** @var ImportRun $importRun */
        $importRun = ImportRun::query()
            ->where('id', $this->importRunId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $importer->runFromUpload(
            $importRun->raw_file_path,
            $importRun->source_format,
            $user,
            basename($importRun->raw_file_path),
        );

        $this->accountName = '';
    }

    public function confirm(
        ConfirmsImports $confirmer,
        CurrentUser $currentUser,
        UrlGenerator $urls,
    ): void {
        try {
            ($confirmer)($this->importRunId, $currentUser->user());
        } catch (PreviewExpiredException) {
            $this->previewExpired = true;

            return;
        }

        $this->redirect(
            $urls->route('imports.results', ['id' => $this->importRunId]),
            navigate: false,
        );
    }

    public function discard(
        DiscardImport $discarder,
        CurrentUser $currentUser,
        UrlGenerator $urls,
    ): void {
        ($discarder)($this->importRunId, $currentUser->user());

        $this->redirect($urls->route('imports.new'), navigate: false);
    }

    public function render(ViewFactory $views, PreviewCache $cache): View
    {
        $preview = $cache->getPreview($this->importRunId);

        return $views->make('import::livewire.preview-wizard', [
            'preview' => $preview,
            'previewExpired' => $this->previewExpired,
        ]);
    }
}
