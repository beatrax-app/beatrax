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
 * @link ../../../../../.docs/features/tax/architecture.md
 */
final class TaxSettingsSection extends Component
{
    /**
     * @var list<string>
     */
    private const ALLOWED_COUNTRIES = ['nl', 'de', 'be', 'fr', 'gb', 'us'];

    public string $taxCountryCode = '';

    public string $newCategoryName = '';

    public string $addError = '';

    public string $renameError = '';

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

    public function setTaxCountry(
        string $code,
        CurrentUser $currentUser,
        DatabaseManager $db,
        Clock $clock,
        TaxCategoryWriter $writer,
    ): void {
        if (! in_array($code, self::ALLOWED_COUNTRIES, strict: true)) {
            return;
        }

        $user = $currentUser->user();

        $writer->seedFromCorpus($user, $code);

        $db->connection()->table('users')
            ->where('id', $user->id)
            ->update([
                'tax_country_code' => $code,
                'updated_at' => $clock->now()->toDateTimeString(),
            ]);

        $this->taxCountryCode = $code;
    }

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

    // Guards against empty and duplicate names with a friendly inline
    // error instead of an uncaught exception.
    public function renameCategory(
        int $categoryId,
        string $name,
        CurrentUser $currentUser,
        TaxCategoryWriter $writer,
    ): void {
        $this->renameError = '';

        // A NotFoundHttpException here means a cross-user attempt; every
        // catch in this class silently ignores it in the UI layer rather
        // than surfacing a signal that the id exists.
        try {
            $writer->rename($currentUser->user()->id, $categoryId, $name);
        } catch (NotFoundHttpException) {
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->renameError = $e->getMessage();
        }
    }

    public function archiveCategory(
        int $categoryId,
        CurrentUser $currentUser,
        TaxCategoryWriter $writer,
    ): void {
        try {
            $writer->archive($currentUser->user()->id, $categoryId);
        } catch (NotFoundHttpException) {
        }
    }

    public function unarchiveCategory(
        int $categoryId,
        CurrentUser $currentUser,
        TaxCategoryWriter $writer,
    ): void {
        try {
            $writer->unarchive($currentUser->user()->id, $categoryId);
        } catch (NotFoundHttpException) {
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
