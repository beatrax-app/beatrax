<?php

declare(strict_types=1);

namespace Modules\Community\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Community\Public\Services\CommunityCorpusQuery;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;

final class SharedListSettingsPanel extends Component
{
    public bool $useSharedList = true;

    public bool $offerToContribute = true;

    public bool $updateOnAppUpdates = false;

    public function mount(CurrentUser $currentUser): void
    {
        $user = $currentUser->user();
        $settings = is_array($user->community_settings) ? $user->community_settings : [];

        $this->useSharedList = array_key_exists('useSharedList', $settings)
            ? (bool) $settings['useSharedList']
            : true;
        $this->offerToContribute = array_key_exists('offerToContribute', $settings)
            ? (bool) $settings['offerToContribute']
            : true;
        $this->updateOnAppUpdates = array_key_exists('updateOnAppUpdates', $settings)
            ? (bool) $settings['updateOnAppUpdates']
            : false;
    }

    public function toggleUseSharedList(CurrentUser $currentUser): void
    {
        $this->useSharedList = ! $this->useSharedList;
        $this->persist($currentUser->user(), 'useSharedList', $this->useSharedList);
        $this->dispatch('shared-list-settings:saved');
    }

    public function toggleOfferToContribute(CurrentUser $currentUser): void
    {
        $this->offerToContribute = ! $this->offerToContribute;
        $this->persist($currentUser->user(), 'offerToContribute', $this->offerToContribute);
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

    private function persist(User $user, string $key, bool $value): void
    {
        $settings = is_array($user->community_settings) ? $user->community_settings : [];
        $settings[$key] = $value;
        $user->community_settings = $settings;
        $user->save();
    }
}
