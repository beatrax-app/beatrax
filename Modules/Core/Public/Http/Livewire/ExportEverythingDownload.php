<?php

declare(strict_types=1);

namespace Modules\Core\Public\Http\Livewire;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Core\Internal\Backup\BackupPassphrase;
use Modules\Core\Internal\Backup\ExportEverythingArchive;
use Modules\Core\Internal\Backup\StagedExportHandover;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Core\Public\Support\SqliteDatabase;
use Modules\Mobile\Public\Enums\FileExportOutcome;
use Modules\Mobile\Public\Services\ShareSheetExport;
use Throwable;

final class ExportEverythingDownload extends Component
{
    public string $passphrase = '';

    public string $confirmPassphrase = '';

    #[Locked]
    public string $error = '';

    #[Locked]
    public string $notice = '';

    public function export(
        Repository $config,
        Clock $clock,
        UrlGenerator $urls,
        ShareSheetExport $shareSheet,
        ExportEverythingArchive $archive,
        StagedExportHandover $handover,
    ): void {
        $this->notice = '';
        $this->error = $this->validationError($config);
        if ($this->error !== '') {
            return;
        }

        $stamp = $clock->now()->format('Y-m-d-His');

        try {
            $zipPath = $archive->build($this->passphrase, $stamp);
        } catch (Throwable $e) {
            $this->error = Lang::get('core::backup.errors.create_failed', [
                'message' => SafeExceptionContext::shortName($e),
            ]);

            return;
        }

        $this->reset('passphrase', 'confirmPassphrase');

        $this->deliver($urls, $handover, $shareSheet, $zipPath, 'beatrax-export-'.$stamp.'.zip');
    }

    // A shell that drops the download hands the file to the OS share sheet
    // instead; a refused handover takes the archive with it, so the container
    // does not accumulate whole databases nobody can reach.
    private function deliver(
        UrlGenerator $urls,
        StagedExportHandover $handover,
        ShareSheetExport $shareSheet,
        string $zipPath,
        string $filename,
    ): void {
        // Navigated to rather than returned: a download returned from here is
        // buffered and base64-encoded by Livewire, which is 2.33x the archive
        // in PHP memory before it is copied into the JSON. The route streams
        // the same file and the shell saves it the way it saves any other.
        if (! $shareSheet->replacesWebViewDownload()) {
            $this->redirect($urls->route('core.help.data-locations.export', [
                'token' => $handover->stage($zipPath, $filename),
            ]));

            return;
        }

        // Built with a passphrase, so it carries the encrypted sentence
        // rather than the default, which warns that a file is readable.
        $outcome = $shareSheet->exportFile(
            $zipPath,
            $filename,
            shareMessage: Lang::get('mobile::export.share_message_encrypted'),
        );
        if ($outcome === FileExportOutcome::Shared) {
            $this->notice = $outcome->message();
        } else {
            @unlink($zipPath);
            $this->error = $outcome->message();
        }
    }

    public function render(ViewFactory $views, Repository $config, ShareSheetExport $shareSheet): View
    {
        return $views->make('core::livewire.export-everything-download', [
            'sqliteOnly' => SqliteDatabase::isSqliteBuild($config),
            'canDeliver' => ! $shareSheet->replacesWebViewDownload() || $shareSheet->isAvailable(),
        ]);
    }

    // The first failing precondition's message in display order, or '' when the
    // request is good to proceed. Returning a value rather than early returns
    // keeps export() within the guard-count budget.
    private function validationError(Repository $config): string
    {
        return match (true) {
            strlen($this->passphrase) < BackupPassphrase::MIN_LENGTH => Lang::choice('core::backup.errors.passphrase_min', BackupPassphrase::MIN_LENGTH, ['min' => BackupPassphrase::MIN_LENGTH]),
            $this->passphrase !== $this->confirmPassphrase => Lang::get('core::backup.errors.passphrase_mismatch'),
            ! SqliteDatabase::isSqliteBuild($config) => Lang::get('core::backup.errors.download_sqlite_only'),
            default => '',
        };
    }
}
