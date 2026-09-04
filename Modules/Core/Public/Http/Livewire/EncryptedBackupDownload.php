<?php

declare(strict_types=1);

namespace Modules\Core\Public\Http\Livewire;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Core\Internal\Backup\BackupKeyMaterial;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\FileEncryptor;
use Modules\Core\Public\Exceptions\BackupIoException;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\OwnerOnlyPath;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Core\Public\Support\SqliteDatabase;
use Modules\Mobile\Public\Enums\FileExportOutcome;
use Modules\Mobile\Public\Services\ShareSheetExport;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

final class EncryptedBackupDownload extends Component
{
    private const int MIN_PASSPHRASE_LENGTH = 8;

    public string $passphrase = '';

    public string $confirmPassphrase = '';

    public string $error = '';

    public string $notice = '';

    public function download(
        DatabaseManager $db,
        Repository $config,
        FileEncryptor $encryptor,
        Clock $clock,
        ResponseFactory $responses,
        ShareSheetExport $shareSheet,
        BackupKeyMaterial $keyMaterial,
        OwnerOnlyPath $ownerOnly,
    ): ?BinaryFileResponse {
        $this->notice = '';
        $this->error = $this->downloadValidationError($config);
        if ($this->error !== '') {
            return null;
        }

        $stamp = $clock->now()->format('Y-m-d-His');
        // Stage the plaintext snapshot inside a private 0700 directory under
        // app storage — NEVER sys_get_temp_dir() (world-traversable, e.g.
        // /tmp at 1777). VACUUM INTO creates the file at 0644 via PHP's
        // umask bypass, so the 0700 parent dir is what keeps it private.
        $stagingDir = UserDataPathService::appPath('tmp-backups');
        $plainPath = $stagingDir.DIRECTORY_SEPARATOR.'beatrax-backup-'.$stamp.'-'.bin2hex(random_bytes(4)).'.sqlite';
        $encPath = $plainPath.'.enc';

        try {
            if (! $ownerOnly->directory($stagingDir)) {
                throw new BackupIoException('The staging directory could not be made owner-only.');
            }

            // Consistent snapshot — VACUUM INTO must not run in a transaction
            // and refuses an existing target, so the unique temp path is fresh.
            $escaped = str_replace("'", "''", $plainPath);
            $db->connection()->statement("VACUUM INTO '{$escaped}'");
            if (! is_file($plainPath)) {
                throw new BackupIoException('The database snapshot was not produced.');
            }
            // The snapshot is plaintext on disk until the encryptor has run,
            // and it carries the packed key material — defence in depth on top
            // of the 0700 staging directory, and a mode that cannot be settled
            // is a refusal rather than a download.
            if (! $ownerOnly->file($plainPath)) {
                throw new BackupIoException('The database snapshot could not be made owner-only.');
            }

            // Before the encrypt, because a snapshot that leaves without it is
            // a copy of ciphertext for anyone whose columns are sealed: the
            // keys live in a file beside the database, not inside it.
            $keyMaterial->packInto($plainPath);

            $encryptor->encrypt($plainPath, $encPath, $this->passphrase);
        } catch (Throwable $e) {
            @unlink($plainPath);
            @unlink($encPath);
            // Its own sentence is a developer's, in one language, and the I/O
            // failures in it carry an absolute path. The class name says what
            // failed and is quotable in every locale.
            $this->error = Lang::get('core::backup.errors.create_failed', [
                'message' => SafeExceptionContext::shortName($e),
            ]);

            return null;
        } finally {
            // The plaintext snapshot must never outlive the encryption step —
            // unlink runs unconditionally, success or failure.
            @unlink($plainPath);
        }

        $this->reset('passphrase', 'confirmPassphrase');

        $filename = 'beatrax-backup-'.$stamp.'.sqlite.enc';

        // A shell that drops the download hands the file to the OS share sheet
        // instead; both roads end in one response the caller returns.
        if ($shareSheet->replacesWebViewDownload()) {
            $delivered = $this->handToShareSheet($shareSheet, $encPath, $filename);
        } else {
            $delivered = $responses->download(
                $encPath,
                $filename,
                ['Content-Type' => 'application/octet-stream'],
            )->deleteFileAfterSend();
        }

        return $delivered;
    }

    // Nothing is sent back: the response a shell like this would have received
    // goes nowhere and deleteFileAfterSend() would then destroy the only copy.
    // A refused handover takes the encrypted file with it, so the container
    // does not silently accumulate whole databases nobody can reach.
    private function handToShareSheet(ShareSheetExport $shareSheet, string $encPath, string $filename): null
    {
        $outcome = $shareSheet->exportFile($encPath, $filename);

        if ($outcome === FileExportOutcome::Shared) {
            $this->notice = $outcome->message();
        } else {
            @unlink($encPath);
            $this->error = $outcome->message();
        }

        return null;
    }

    public function render(ViewFactory $views, Repository $config, ShareSheetExport $shareSheet): View
    {
        return $views->make('core::livewire.encrypted-backup-download', [
            'sqliteOnly' => SqliteDatabase::isSqliteBuild($config),
            // A BinaryFileResponse is only a backup where the shell saves what
            // its WebView downloads. Where it does not the OS share sheet is
            // the route, and only a shell without one has nothing to offer.
            'canDeliver' => ! $shareSheet->replacesWebViewDownload() || $shareSheet->isAvailable(),
        ]);
    }

    // The first failing precondition's message in display order, or '' when
    // the request is good to proceed. Returning a value rather than early
    // returns keeps download() within the guard-count budget.
    private function downloadValidationError(Repository $config): string
    {
        return match (true) {
            strlen($this->passphrase) < self::MIN_PASSPHRASE_LENGTH => Lang::choice('core::backup.errors.passphrase_min', self::MIN_PASSPHRASE_LENGTH, ['min' => self::MIN_PASSPHRASE_LENGTH]),
            $this->passphrase !== $this->confirmPassphrase => Lang::get('core::backup.errors.passphrase_mismatch'),
            ! SqliteDatabase::isSqliteBuild($config) => Lang::get('core::backup.errors.download_sqlite_only'),
            default => '',
        };
    }
}
