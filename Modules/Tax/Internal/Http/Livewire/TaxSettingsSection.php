<?php

declare(strict_types=1);

namespace Modules\Tax\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Tax\Internal\Actions\TaxCategoryWriter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Settings Tax section component.
 *
 * Exposes country selection + deduction category CRUD in the settings page.
 * Follows the SettingsPage pattern: NO constructor DI — collaborators arrive
 * as parameters on each action/render method.
 *
 * Allow-listed country codes (D-07, T-07-12): nl, de, be, fr, gb, us.
 * Switching country is additive — never deletes existing categories or tags.
 */
final class TaxSettingsSection extends Component
{
    /** @var list<string> Allowed country codes (T-07-12). */
    private const ALLOWED_COUNTRIES = ['nl', 'de', 'be', 'fr', 'gb', 'us'];

    /** Current tax country code or empty string when unset. */
    public string $taxCountryCode = '';

    /** Inline category add form — name input. */
    public string $newCategoryName = '';

    /** Inline error message for the add form. */
    public string $addError = '';

    /** Inline error message for the rename affordance (WR-11). */
    public string $renameError = '';

    /** Success flash. */
    public bool $addSuccess = false;

    public function mount(CurrentUser $currentUser, DatabaseManager $db): void
    {
        $user = $currentUser->user();

        /** @var string|null $code */
        $code = $db->connection()
            ->table('users')
            ->where('id', $user->id)
            ->value('tax_country_code');

        $this->taxCountryCode = is_string($code) ? $code : '';
    }

    /**
     * Set the user's tax country and seed the corpus categories for the new
     * country (additive — never deletes existing categories, D-07, T-07-12).
     */
    public function setTaxCountry(
        string $code,
        CurrentUser $currentUser,
        DatabaseManager $db,
        Clock $clock,
        TaxCategoryWriter $writer,
    ): void {
        if (! in_array($code, self::ALLOWED_COUNTRIES, strict: true)) {
            // Out-of-allow-list codes are silently ignored (T-07-12).
            return;
        }

        $user = $currentUser->user();

        // Seed the new country's categories (INSERT-only, never deletes).
        $writer->seedFromCorpus($user, $code);

        // Persist the preference.
        $db->connection()->table('users')
            ->where('id', $user->id)
            ->update([
                'tax_country_code' => $code,
                'updated_at' => $clock->now()->toDateTimeString(),
            ]);

        $this->taxCountryCode = $code;
    }

    /**
     * Add a user-created category via the inline form.
     */
    public function addCategory(CurrentUser $currentUser, TaxCategoryWriter $writer): void
    {
        $this->addError = '';
        $name = trim($this->newCategoryName);

        if ($name === '') {
            $this->addError = 'Category name cannot be empty.';

            return;
        }

        try {
            $writer->add($currentUser->user()->id, $name);
            $this->newCategoryName = '';
            $this->addSuccess = true;
        } catch (\RuntimeException $e) {
            $this->addError = $e->getMessage();
        }
    }

    /**
     * Rename a category inline (WR-11: guards against empty and duplicate
     * names with a friendly inline error instead of an uncaught exception).
     */
    public function renameCategory(
        int $categoryId,
        string $name,
        CurrentUser $currentUser,
        TaxCategoryWriter $writer,
    ): void {
        $this->renameError = '';

        try {
            $writer->rename($currentUser->user()->id, $categoryId, $name);
        } catch (NotFoundHttpException) {
            // Cross-user attempt — silently ignore in the UI layer.
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->renameError = $e->getMessage();
        }
    }

    /**
     * Archive a category.
     */
    public function archiveCategory(
        int $categoryId,
        CurrentUser $currentUser,
        TaxCategoryWriter $writer,
    ): void {
        try {
            $writer->archive($currentUser->user()->id, $categoryId);
        } catch (NotFoundHttpException) {
            // Cross-user attempt — silently ignore in the UI layer.
        }
    }

    /**
     * Restore an archived category to active (WR-11 — archiving is
     * reversible from the Archived disclosure).
     */
    public function unarchiveCategory(
        int $categoryId,
        CurrentUser $currentUser,
        TaxCategoryWriter $writer,
    ): void {
        try {
            $writer->unarchive($currentUser->user()->id, $categoryId);
        } catch (NotFoundHttpException) {
            // Cross-user attempt — silently ignore in the UI layer.
        }
    }

    public function render(
        CurrentUser $currentUser,
        TaxCategoryWriter $writer,
        ViewFactory $views,
    ): View {
        if (! $currentUser->isAuthenticated()) {
            return $views->make('tax::livewire.tax-settings-section', [
                'categories' => [],
                'allowedCountries' => self::ALLOWED_COUNTRIES,
            ]);
        }

        $categories = $writer->listForUser($currentUser->user()->id, includeArchived: true);

        return $views->make('tax::livewire.tax-settings-section', [
            'categories' => $categories,
            'allowedCountries' => self::ALLOWED_COUNTRIES,
        ]);
    }
}
