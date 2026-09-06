<?php

declare(strict_types=1);

namespace Modules\Core\Public\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Core\Public\Actions\WriteUserPreference;
use Modules\Core\Public\Enums\UpdateChannel;
use Modules\Core\Public\Services\UpdateChannelPreference;
use Modules\Core\Public\Services\UserDataPathService;

/**
 * @link ../../../../../.docs/features/desktop/auto-update.md#the-two-channels
 */
final class UpdateChannelSettingsSection extends Component
{
    // Stable until a stored answer says otherwise, and typed as the string the
    // select posts rather than as the enum: a crafted /livewire/update may set
    // any value it likes on a bound property, and an enum-typed one would take
    // the unbacked case as a fatal instead of as an answer to refuse.
    public string $channel = UpdateChannel::Stable->value;

    // The same question the three AutoUpdater listeners ask, and the same one
    // the switch above this control asks. A store build owns no self-update
    // path at all, so the channel of one is a control over nothing.
    #[Locked]
    public bool $onPhone = false;

    public function mount(UpdateChannelPreference $channels): void
    {
        $this->channel = $channels->channel()->value;
        $this->onPhone = UserDataPathService::isMobileRuntime();
    }

    // A public endpoint whatever the view draws, so the phone refusal and the
    // unknown-value refusal both live here rather than in the markup. Either
    // way the property is put back to the stored answer, so a refused choice
    // does not linger on the screen as though it had been taken.
    public function choose(UpdateChannelPreference $channels, WriteUserPreference $writeUserPreference): void
    {
        $chosen = UpdateChannel::tryFrom($this->channel);

        if (! $this->onPhone && $chosen !== null) {
            $channels->choose($writeUserPreference, $chosen);
        }

        $this->channel = $channels->channel()->value;
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('core::livewire.update-channel-settings-section');
    }
}
