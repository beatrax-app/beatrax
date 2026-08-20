<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Actions\WriteUserPreference;
use Modules\Core\Public\Contracts\CurrentUser;

final class AutoImportSettingsSection extends Component
{
    public bool $enabled = false;

    public int $userId = 0;

    public function mount(CurrentUser $currentUser): void
    {
        $user = $currentUser->user();

        $this->userId = $user->id;
        $this->enabled = (bool) $user->auto_import_drop_folder;
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
