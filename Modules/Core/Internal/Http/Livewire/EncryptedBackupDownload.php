<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Livewire;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\FileEncryptor;
use Modules\Core\Public\Exceptions\BackupIoException;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\Lang;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * @link ../../../../../.docs/features/core/architecture.md
 */
final class EncryptedBackupDownload extends Component
{
    private const MIN_PASSPHRASE_LENGTH = 8;

    public string $passphrase = '';

    public string $confirmPassphrase = '';

    public string $error = '';

    public function download(
        DatabaseManager $db,
        Repository $config,
        FileEncryptor $encryptor,
        Clock $clock,
        ResponseFactory $responses,
    ): ?BinaryFileResponse {
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
        @mkdir($stagingDir, 0700, true);
        @chmod($stagingDir, 0700);
        $plainPath = $stagingDir.DIRECTORY_SEPARATOR.'beatrax-backup-'.$stamp.'-'.bin2hex(random_bytes(4)).'.sqlite';
        $encPath = $plainPath.'.enc';

        try {
            // Consistent snapshot — VACUUM INTO must not run in a transaction
            // and refuses an existing target, so the unique temp path is fresh.
            $escaped = str_replace("'", "''", $plainPath);
            $db->connection()->statement("VACUUM INTO '{$escaped}'");
            if (! is_file($plainPath)) {
                throw new BackupIoException('The database snapshot was not produced.');
            }
            // The snapshot is briefly plaintext on disk — lock the file itself
            // down too (defense in depth on top of the 0700 dir), mirroring
            // db:backup's chmod 0600.
            @chmod($plainPath, 0600);

            $encryptor->encrypt($plainPath, $encPath, $this->passphrase);
        } catch (Throwable $e) {
            @unlink($plainPath);
            @unlink($encPath);
            $this->error = Lang::get('core::backup.errors.create_failed', ['message' => $e->getMessage()]);

            return null;
        } finally {
            // The plaintext snapshot must never outlive the encryption step —
            // unlink runs unconditionally, success or failure.
            @unlink($plainPath);
        }

        $this->reset('passphrase', 'confirmPassphrase');

        return $responses->download(
            $encPath,
            'beatrax-backup-'.$stamp.'.sqlite.enc',
            ['Content-Type' => 'application/octet-stream'],
        )->deleteFileAfterSend();
    }

    public function render(ViewFactory $views, Repository $config): View
    {
        return $views->make('core::livewire.encrypted-backup-download', [
            'sqliteOnly' => $this->isSqliteBuild($config),
        ]);
    }

    // The first failing precondition's message in display order, or '' when
    // the request is good to proceed. Returning a value rather than early
    // returns keeps download() within the guard-count budget.
    private function downloadValidationError(Repository $config): string
    {
        return match (true) {
            strlen($this->passphrase) < self::MIN_PASSPHRASE_LENGTH => Lang::get('core::backup.errors.passphrase_min', ['min' => self::MIN_PASSPHRASE_LENGTH]),
            $this->passphrase !== $this->confirmPassphrase => Lang::get('core::backup.errors.passphrase_mismatch'),
            ! $this->isSqliteBuild($config) => Lang::get('core::backup.errors.download_sqlite_only'),
            default => '',
        };
    }

    // Checks the driver (not the connection name) so the in-memory test
    // connection and a real sqlite file both qualify, while a server's
    // Postgres/MySQL default does not.
    private function isSqliteBuild(Repository $config): bool
    {
        $default = $config->get('database.default');

        return is_string($default)
            && $config->get('database.connections.'.$default.'.driver') === 'sqlite';
    }
}
