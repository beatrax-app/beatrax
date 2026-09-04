<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Core\Public\Enums\RestoreRefusal;
use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Services\RestoreEncryptedBackup;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Mobile\Internal\Boot\MobileFirstLaunchBootstrap;
use Psr\Log\LoggerInterface;
use RuntimeException;

// The route back from a wipe. Before this a reader holding a .enc had to
// invent a throwaway account, find an unadvertised page, restore, and sign in
// as the user inside the backup -- leaving the throwaway behind. The welcome
// screen offered pairing, which needs a second live device, and nothing else.
final class MobileRestoreFromBackup extends Component
{
    use WithFileUploads;

    public ?TemporaryUploadedFile $backup = null;

    public string $passphrase = '';

    public string $error = '';

    public function mount(MobileFirstLaunchBootstrap $bootstrap): void
    {
        if (! $bootstrap->isFreshInstall()) {
            $this->redirectRoute(Destination::Dashboard->routeName());
        }
    }

    public function uploadFailed(): void
    {
        $this->error = Lang::get('core::backup.errors.upload_failed');
    }

    public function restore(RestoreEncryptedBackup $restore, MobileFirstLaunchBootstrap $bootstrap, LoggerInterface $logger): void
    {
        $this->error = '';

        // Checked again here, not only in mount(). A Livewire action does not
        // re-run mount, so the guard has to sit on the thing that replaces the
        // database or a direct call walks past it. Zero users is the whole
        // bound on what this can destroy.
        if (! $bootstrap->isFreshInstall()) {
            $this->redirectRoute(Destination::Dashboard->routeName());

            return;
        }

        $backup = $this->backup;
        $path = $backup instanceof TemporaryUploadedFile ? $backup->getRealPath() : '';

        $this->error = match (true) {
            ! $backup instanceof TemporaryUploadedFile => Lang::get('core::backup.errors.choose_file'),
            $this->passphrase === '' => Lang::get('core::backup.errors.enter_passphrase'),
            $path === '' || ! is_file($path) => Lang::get('core::backup.errors.unreadable'),
            default => '',
        };

        if ($this->error !== '') {
            return;
        }

        try {
            $restore($path, $this->passphrase);
        } catch (RuntimeException $e) {
            $logger->warning('MobileRestoreFromBackup: restore refused.', SafeExceptionContext::describe($e));
            $this->error = RestoreRefusal::forThrowable($e)->sentence();

            return;
        }

        // The sign-in lives in the database that was just replaced, so the
        // credentials that work now are the ones inside the backup.
        $this->redirectRoute('login');
    }

    public function render(ViewFactory $views): View
    {
        $view = $views->make('mobile::livewire.mobile-restore-from-backup');

        $view->extends('layouts.app', ['title' => Lang::get('mobile::restore.page_title').' · Beatrax']);

        return $view;
    }
}
