<?php

declare(strict_types=1);

namespace Modules\Community\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Community\Public\Enums\CommunitySetting;
use Modules\Community\Public\Services\CommunityCorpusQuery;
use Modules\Community\Public\Services\CommunitySettings;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\UserDataPathService;

final class SharedListSettingsPanel extends Component
{
    // Locked because the switches carry wire:click only, and each toggle
    // negates the property it then persists. Unlocked, that negation ran over
    // the client's value rather than the stored one, so a payload chose the
    // saved flag outright -- and this one gates merchant auto-naming.
    #[Locked]
    public bool $useSharedList = true;

    #[Locked]
    public bool $offerToContribute = true;

    public bool $updateOnAppUpdates = false;

    // What "an app update" is differs by platform: the desktop's updater chain
    // installs one itself, a phone receives one from its store. Locked because
    // it only ever picks which sentence renders.
    #[Locked]
    public bool $onPhone = false;

    public function mount(CurrentUser $currentUser): void
    {
        $settings = self::settingsOf($currentUser->user());

        $this->onPhone = UserDataPathService::platform() !== null;

        $this->useSharedList = CommunitySettings::readFrom($settings, CommunitySetting::UseSharedList);
        $this->offerToContribute = CommunitySettings::readFrom($settings, CommunitySetting::OfferToContribute);
        $this->updateOnAppUpdates = CommunitySettings::readFrom($settings, CommunitySetting::UpdateOnAppUpdates);
    }

    public function toggleUseSharedList(CurrentUser $currentUser): void
    {
        $this->useSharedList = ! $this->useSharedList;
        $this->persist($currentUser->user(), CommunitySetting::UseSharedList, $this->useSharedList);
        $this->dispatch('shared-list-settings:saved');
    }

    public function toggleOfferToContribute(CurrentUser $currentUser): void
    {
        $this->offerToContribute = ! $this->offerToContribute;
        $this->persist($currentUser->user(), CommunitySetting::OfferToContribute, $this->offerToContribute);
        $this->dispatch('shared-list-settings:saved');
    }

    public function toggleUpdateOnAppUpdates(): void
    {
        // The disabled checkbox is only the user-facing speed bump; this
        // no-op is what stops a forged Livewire call writing the column.
    }

    public function render(ViewFactory $views, CommunityCorpusQuery $corpus): View
    {
        return $views->make('community::livewire.shared-list-settings-panel', [
            'mappingsCount' => $corpus->mappingsCount(),
            'contributorCount' => $corpus->contributorsCount(),
        ]);
    }

    private function persist(User $user, CommunitySetting $setting, bool $value): void
    {
        $settings = self::settingsOf($user);
        $settings[$setting->value] = $value;
        $user->community_settings = $settings;
        $user->save();
    }

    /**
     * @return array<string, mixed>
     */
    private static function settingsOf(User $user): array
    {
        return is_array($user->community_settings) ? $user->community_settings : [];
    }
}
