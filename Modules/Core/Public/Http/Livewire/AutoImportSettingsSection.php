<?php

declare(strict_types=1);

namespace Modules\Core\Public\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Core\Public\Actions\WriteUserPreference;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\UserDataPathService;

final class AutoImportSettingsSection extends Component
{
    public bool $enabled = false;

    // Rendered into the drop-folder path the copy shows; a wire-writable id
    // is the shape a later authorization read reaches for.
    #[Locked]
    public int $userId = 0;

    // Five minutes is the desktop scheduler's real cadence. A device runner
    // clamps anything under fifteen and hands the interval to an OS that
    // treats it as a floor, so the phone is told only what it can keep.
    #[Locked]
    public bool $onPhone = false;

    public function mount(CurrentUser $currentUser): void
    {
        $user = $currentUser->user();

        $this->userId = $user->id;
        $this->enabled = (bool) $user->auto_import_drop_folder;
        $this->onPhone = UserDataPathService::platform() !== null;
    }

    // The view binds the checkbox via wire:change only (no wire:model.live),
    // so this single round-trip covers both the property update and the DB
    // write, avoiding a double round-trip.
    public function toggle(CurrentUser $currentUser, WriteUserPreference $writeUserPreference): void
    {
        $this->enabled = ! $this->enabled;

        ($writeUserPreference)($currentUser->user()->id, ['auto_import_drop_folder' => $this->enabled]);
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('core::livewire.auto-import-settings-section');
    }
}
