<?php

declare(strict_types=1);

namespace Modules\Community\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;

/**
 * Per-row "Help others identify this" CTA mounted inside each triage
 * row. Gated SERVER-SIDE on the user's
 * `community_settings.offerToContribute` toggle — when off, the render
 * method returns an empty view so the CTA is structurally absent from
 * the DOM (not just CSS-hidden, per the UI-SPEC's "Empty / loading /
 * error states" guidance and the T-16.1-05-06 mitigation in the
 * threat model).
 *
 * Clicking the CTA dispatches `suggest-mapping:open` with the row's
 * verbatim raw description so the globally-mounted
 * SuggestMappingModal opens with the pattern prefilled.
 *
 * Service collaborators arrive as parameters on action methods — never
 * via constructor injection.
 */
final class HelpOthersTriageButton extends Component
{
    public string $raw = '';

    public bool $visible = true;

    public function mount(CurrentUser $currentUser, string $raw): void
    {
        $this->raw = $raw;
        $this->visible = $this->readVisibility($currentUser);
    }

    #[On('shared-list-settings:saved')]
    public function refreshVisibility(CurrentUser $currentUser): void
    {
        $this->visible = $this->readVisibility($currentUser);
    }

    public function clicked(): void
    {
        if (! $this->visible) {
            return;
        }

        $this->dispatch('suggest-mapping:open', rawDescription: $this->raw);
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('community::livewire.help-others-triage-button');
    }

    private function readVisibility(CurrentUser $currentUser): bool
    {
        $user = $currentUser->user();
        $settings = is_array($user->community_settings) ? $user->community_settings : [];

        return array_key_exists('offerToContribute', $settings)
            ? (bool) $settings['offerToContribute']
            : true;
    }
}
