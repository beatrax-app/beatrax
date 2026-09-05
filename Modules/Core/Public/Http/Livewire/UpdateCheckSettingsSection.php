<?php

declare(strict_types=1);

namespace Modules\Core\Public\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Community\Public\Actions\OpenExternalUrlAction;
use Modules\Core\Public\Actions\WriteUserPreference;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\ProjectLinks;

/**
 * @link ../../../../../.docs/features/desktop/auto-update.md#the-off-switch
 */
final class UpdateCheckSettingsSection extends Component
{
    // On rather than off, and read back with the same fallback: the signed
    // manifest is the only binary-integrity signal a bundle without a paid
    // signing identity carries, so a row that reads null must not read as an
    // opt-out the reader never made.
    public bool $enabled = true;

    // The desktop's electron-updater chain is inert on a device: all three
    // AutoUpdater listeners return early on a mobile runtime, so the section
    // names the store that does the updating and offers no switch at all.
    #[Locked]
    public bool $onPhone = false;

    public function mount(CurrentUser $currentUser): void
    {
        $this->enabled = $currentUser->user()->auto_update_check_enabled ?? true;
        $this->onPhone = UserDataPathService::platform() !== null;
    }

    public function toggle(CurrentUser $currentUser, WriteUserPreference $writeUserPreference): void
    {
        $this->enabled = ! $this->enabled;

        ($writeUserPreference)($currentUser->user()->id, ['auto_update_check_enabled' => $this->enabled]);
    }

    public function openReleasesPage(OpenExternalUrlAction $opener): void
    {
        $opener(ProjectLinks::LATEST_RELEASE_URL);
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('core::livewire.update-check-settings-section');
    }
}
