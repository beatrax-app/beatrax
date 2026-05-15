<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;

/**
 * Settings page. Surfaces two user preferences:
 *
 *  - `defaultCurrencyView` — chooses the default `/transactions` and
 *    dashboard presentation between EUR-only (settled-EUR pair only) and
 *    Original currency (native pair with settled secondary line). The per-
 *    page toggle on /transactions overrides this default via the
 *    `?currency=` query parameter; the global default lives on the users
 *    row so it survives across sessions and devices.
 *  - `periodStartDay` — the day of the month at which the "this period"
 *    window rolls over. Numbered 1..28 so every calendar month including
 *    February has a valid value. Salary-aligned users typically pick 25.
 *
 * Service collaborators arrive as parameters on each action method; the
 * Livewire strict-rules ruleset forbids constructor-DI on Component
 * subclasses, so CurrentUser / ViewFactory are resolved per call. The
 * authenticated user is read exclusively from CurrentUser — never from
 * a request-supplied user_id — so cross-user writes are structurally
 * impossible.
 */
final class SettingsPage extends Component
{
    #[Validate('required|in:eur_only,original')]
    public string $defaultCurrencyView = 'eur_only';

    #[Validate('required|integer|min:1|max:28')]
    public int $periodStartDay = 1;

    /**
     * Inline "Saved." confirmation flag flipped by save() and consumed by
     * the Blade view via `@if ($saved)` + `wire:transition.duration.4000ms`
     * so the confirmation auto-dismisses after four seconds.
     */
    public bool $saved = false;

    public function mount(CurrentUser $currentUser): void
    {
        $user = $currentUser->user();
        $this->defaultCurrencyView = $user->default_currency_view;
        $this->periodStartDay = $user->period_start_day;
    }

    public function save(CurrentUser $currentUser): void
    {
        $this->validate();

        $user = $currentUser->user();
        $user->default_currency_view = $this->defaultCurrencyView;
        $user->period_start_day = $this->periodStartDay;
        $user->save();

        $this->saved = true;
        $this->dispatch('settings-saved');
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('core::livewire.settings-page');
    }

    /**
     * Custom validation messages locked to the phase-3 UI design contract.
     * The boundary check for `periodStartDay` returns the same message for
     * any failure of integer / min / max so the user sees one calm
     * sentence regardless of which sub-rule flagged the value.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'periodStartDay.required' => 'Choose a day from 1 to 28.',
            'periodStartDay.integer' => 'Choose a day from 1 to 28.',
            'periodStartDay.min' => 'Choose a day from 1 to 28.',
            'periodStartDay.max' => 'Choose a day from 1 to 28.',
            'defaultCurrencyView.required' => 'Pick one of the available options.',
            'defaultCurrencyView.in' => 'Pick one of the available options.',
        ];
    }
}
