<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Livewire;

use Illuminate\Config\Repository;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Core\Public\Services\RestoreEncryptedBackup;
use RuntimeException;

/**
 * @link ../../../../../.docs/features/core/architecture.md
 */
final class EncryptedBackupRestore extends Component
{
    use WithFileUploads;

    public const CONFIRM_PHRASE = 'RESTORE';

    public ?TemporaryUploadedFile $backup = null;

    public string $passphrase = '';

    public string $confirmation = '';

    public string $error = '';

    public string $snapshotPath = '';

    public function restore(RestoreEncryptedBackup $restore): void
    {
        $this->error = '';
        $this->snapshotPath = '';

        if ($this->confirmation !== self::CONFIRM_PHRASE) {
            $this->error = 'Type '.self::CONFIRM_PHRASE.' to confirm — this replaces your current data.';

            return;
        }
        if (! $this->backup instanceof TemporaryUploadedFile) {
            $this->error = 'Choose an encrypted backup file (.enc) to restore.';

            return;
        }
        if ($this->passphrase === '') {
            $this->error = 'Enter the passphrase the backup was encrypted with.';

            return;
        }

        $path = $this->backup->getRealPath();
        if ($path === '' || ! is_file($path)) {
            $this->error = 'The uploaded file could not be read. Try again.';

            return;
        }

        try {
            $this->snapshotPath = $restore($path, $this->passphrase);
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->reset('backup', 'passphrase', 'confirmation');
    }

    public function render(ViewFactory $views, Repository $config): View
    {
        return $views->make('core::livewire.encrypted-backup-restore', [
            'sqliteOnly' => $this->isSqliteBuild($config),
            'confirmPhrase' => self::CONFIRM_PHRASE,
        ]);
    }

    private function isSqliteBuild(Repository $config): bool
    {
        $default = $config->get('database.default');

        return is_string($default)
            && $config->get('database.connections.'.$default.'.driver') === 'sqlite';
    }
}
