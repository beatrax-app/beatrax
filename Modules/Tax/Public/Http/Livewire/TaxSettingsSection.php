<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\UserCountry;
use Modules\Core\Public\Support\Lang;
use Modules\Tax\Internal\Actions\TaxCategoryWriter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class TaxSettingsSection extends Component
{
    public string $newCategoryName = '';

    public string $addError = '';

    public string $renameError = '';

    public bool $addSuccess = false;

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
        UserCountry $countries,
        ViewFactory $views,
    ): View {
        // A hydrate whose session expired must still render, not throw when it
        // reaches user().
        if (! $currentUser->isAuthenticated()) {
            return $views->make('tax::livewire.tax-settings-section', [
                'categories' => [],
                'countryLabel' => '',
            ]);
        }

        $userId = $currentUser->user()->id;
        $countryCode = $countries->current($userId);

        return $views->make('tax::livewire.tax-settings-section', [
            'categories' => $writer->listForUser($userId, includeArchived: true),
            'countryLabel' => $countryCode === ''
                ? ''
                : Lang::get('core::settings.country.countries.'.$countryCode),
        ]);
    }
}
