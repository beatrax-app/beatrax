<?php

declare(strict_types=1);

namespace Modules\Core\Public\Http\Livewire;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Core\Internal\Backup\BackupPassphrase;
use Modules\Core\Internal\Backup\ExportEverythingArchive;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Core\Public\Support\SqliteDatabase;
use Modules\Mobile\Public\Enums\FileExportOutcome;
use Modules\Mobile\Public\Services\ShareSheetExport;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
        ResponseFactory $responses,
        ShareSheetExport $shareSheet,
        ExportEverythingArchive $archive,
    ): ?BinaryFileResponse {
        $this->notice = '';
        $this->error = $this->validationError($config);
        if ($this->error !== '') {
            return null;
        }

        $stamp = $clock->now()->format('Y-m-d-His');

        try {
            $zipPath = $archive->build($this->passphrase, $stamp);
        } catch (Throwable $e) {
            $this->error = Lang::get('core::backup.errors.create_failed', [
                'message' => SafeExceptionContext::shortName($e),
            ]);

            return null;
        }

        $this->reset('passphrase', 'confirmPassphrase');

        return $this->deliver($responses, $shareSheet, $zipPath, 'beatrax-export-'.$stamp.'.zip');
    }

    // A shell that drops the download hands the file to the OS share sheet
    // instead; a refused handover takes the archive with it, so the container
    // does not accumulate whole databases nobody can reach.
    private function deliver(
        ResponseFactory $responses,
        ShareSheetExport $shareSheet,
        string $zipPath,
        string $filename,
    ): ?BinaryFileResponse {
        if (! $shareSheet->replacesWebViewDownload()) {
            return $responses->download($zipPath, $filename, ['Content-Type' => 'application/zip'])
                ->deleteFileAfterSend();
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

        return null;
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
