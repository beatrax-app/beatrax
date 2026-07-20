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
use Modules\Core\Public\Services\BackupEncryptor;
use Modules\Core\Public\Services\UserDataPathService;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Settings → Data & backup: produce a passphrase-encrypted snapshot of the
 * SQLite database and stream it to the browser as a download.
 *
 * The snapshot is taken with `VACUUM INTO` (a consistent copy, like db:backup),
 * encrypted in place with BackupEncryptor (quantum-safe Argon2id +
 * XChaCha20-Poly1305), and the plaintext temp is deleted before the encrypted
 * file is sent. The passphrase never persists — it is cleared after each run
 * and is the only thing that can decrypt the file, so losing it means the
 * backup is unrecoverable (stated in the UI).
 *
 * SQLite-only: the encrypted-download flow targets the single-file desktop
 * build; on a server (Postgres/MySQL) the section is hidden.
 */
final class EncryptedBackupDownload extends Component
{
    public string $passphrase = '';

    public string $confirmPassphrase = '';

    public string $error = '';

    public function download(
        DatabaseManager $db,
        Repository $config,
        BackupEncryptor $encryptor,
        Clock $clock,
        ResponseFactory $responses,
    ): ?BinaryFileResponse {
        $this->error = '';

        if (strlen($this->passphrase) < 8) {
            $this->error = 'Use a passphrase of at least 8 characters.';

            return null;
        }
        if ($this->passphrase !== $this->confirmPassphrase) {
            $this->error = 'The two passphrases do not match.';

            return null;
        }

        if (! $this->isSqliteBuild($config)) {
            $this->error = 'Encrypted download is only available on the SQLite build.';

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
                throw new RuntimeException('The database snapshot was not produced.');
            }
            // The snapshot is briefly plaintext on disk — lock the file itself
            // down too (defense in depth on top of the 0700 dir), mirroring
            // db:backup's chmod 0600.
            @chmod($plainPath, 0600);

            $encryptor->encrypt($plainPath, $encPath, $this->passphrase);
        } catch (Throwable $e) {
            @unlink($plainPath);
            @unlink($encPath);
            $this->error = 'Could not create the backup: '.$e->getMessage();

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

    /**
     * True when the default connection is SQLite — the single-file desktop
     * build. Checks the driver (not the connection name) so the in-memory test
     * connection and a real sqlite file both qualify, while a server's
     * Postgres/MySQL default does not.
     */
    private function isSqliteBuild(Repository $config): bool
    {
        $default = $config->get('database.default');

        return is_string($default)
            && $config->get('database.connections.'.$default.'.driver') === 'sqlite';
    }
}
