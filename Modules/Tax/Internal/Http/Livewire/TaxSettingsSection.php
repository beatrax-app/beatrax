<?php

declare(strict_types=1);

namespace Modules\Tax\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Core\Public\Actions\WriteUserPreference;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Tax\Internal\Actions\TaxCategoryWriter;
use Modules\Tax\Public\Enums\TaxCountry;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class TaxSettingsSection extends Component
{
    public string $taxCountryCode = '';

    public string $newCategoryName = '';

    public string $addError = '';

    public string $renameError = '';

    public bool $addSuccess = false;

    public function mount(CurrentUser $currentUser, DatabaseManager $db): void
    {
        // A hydrate whose session expired must still mount and render, not throw
        // when it reaches user().
        if (! $currentUser->isAuthenticated()) {
            return;
        }

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
        WriteUserPreference $writeUserPreference,
        TaxCategoryWriter $writer,
    ): void {
        if (TaxCountry::tryFrom($code) === null) {
            return;
        }

        $user = $currentUser->user();

        $writer->seedFromCorpus($user, $code);

        ($writeUserPreference)($user->id, ['tax_country_code' => $code]);

        $this->taxCountryCode = $code;
    }

    public function addCategory(CurrentUser $currentUser, TaxCategoryWriter $writer): void
    {
        $this->addError = '';
        $name = trim($this->newCategoryName);

        if ($name === '') {
            $this->addError = Lang::get('tax::messages.errors.name_empty');

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
            // A cross-user id stays silent: reporting it confirms the row exists.
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
            // A cross-user id stays silent: reporting it confirms the row exists.
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
            // A cross-user id stays silent: reporting it confirms the row exists.
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
                'allowedCountries' => array_column(TaxCountry::cases(), 'value'),
            ]);
        }

        $categories = $writer->listForUser($currentUser->user()->id, includeArchived: true);

        return $views->make('tax::livewire.tax-settings-section', [
            'categories' => $categories,
            'allowedCountries' => array_column(TaxCountry::cases(), 'value'),
        ]);
    }
}
