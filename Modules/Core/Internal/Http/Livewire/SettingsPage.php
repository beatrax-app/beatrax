<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Core\Public\Contracts\Clock;
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
     * Watched-folder secondary path toggle. When on,
     * ScanInboxDropFolderJob runs every 5 minutes for this user and
     * imports any .eml / .mbox files in
     * storage/app/inbox-drop/{userId}/ through the same matcher
     * pipeline as the wizard upload path. Default off so the wizard
     * remains the documented primary entrypoint.
     */
    public bool $autoImportFromDropFolder = false;

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
        $this->autoImportFromDropFolder = (bool) $user->auto_import_drop_folder;
    }

    /**
     * Instant-apply toggle for the watched-folder secondary path —
     * mirrors the currency-display section's "no Save button"
     * posture (the toggle is its own commit). The Blade view binds
     * the checkbox via `wire:change="toggleAutoImport"` only (no
     * `wire:model.live`); the handler flips the property explicitly
     * so a single round-trip covers both the property update and the
     * DB write. Avoids the double round-trip the combined
     * model.live + change binding would emit.
     */
    public function toggleAutoImport(CurrentUser $currentUser, DatabaseManager $db, Clock $clock): void
    {
        $this->autoImportFromDropFolder = ! $this->autoImportFromDropFolder;

        $user = $currentUser->user();
        $db->connection()
            ->table('users')
            ->where('id', $user->id)
            ->update([
                'auto_import_drop_folder' => $this->autoImportFromDropFolder,
                'updated_at' => $clock->now()->toDateTimeString(),
            ]);
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

    public function render(ViewFactory $views, CurrentUser $currentUser): View
    {
        return $views->make('core::livewire.settings-page', [
            // Expose the per-user inbox-drop path so the help text
            // renders the directory the user must actually create
            // (storage/app/inbox-drop/{userId}/) rather than the
            // root inbox-drop folder.
            'userId' => $currentUser->user()->id,
        ]);
    }

    /**
     * Custom validation messages. The boundary check for
     * `periodStartDay` returns the same message for any failure of
     * integer / min / max so the user sees one calm sentence
     * regardless of which sub-rule flagged the value.
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
