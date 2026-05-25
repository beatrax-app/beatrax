<?php

declare(strict_types=1);

namespace Modules\Community\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Community\Public\Services\CommunityCorpusQuery;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;

/**
 * Settings → Shared merchant list panel. Renders three toggles per
 * UI-SPEC: "Use the shared merchant list" (controls whether the
 * MerchantNameResolver consults the community-tier corpus), "Offer to
 * contribute" (controls whether the Triage row's
 * `Help others identify this` CTA appears), and "Update the shared
 * list on app updates" (disabled no-op in this phase — the live-update
 * mechanism activates with a future app update; the inline note is
 * version-agnostic per B-5).
 *
 * Toggle state is persisted in `users.community_settings`, the JSON
 * column added by plan 16.1-01. The Eloquent model casts the column
 * to a PHP array; reads and writes go through `$user->community_settings`
 * directly rather than through a separate UserSettings model.
 *
 * Service collaborators arrive as parameters on action methods — never
 * via constructor injection. The strict-rules ruleset banishes
 * constructor-DI from Livewire components.
 */
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

    /**
     * Toggle 3 ships disabled in this phase: the live-update
     * mechanism activates with a future app update, so the handler
     * intentionally writes nothing to `users.community_settings`. The
     * disabled attribute on the rendered checkbox is the user-facing
     * speed bump; this method is a defensive no-op that protects the
     * column from a forged Livewire call.
     */
    public function toggleUpdateOnAppUpdates(): void
    {
        // No-op by design.
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
