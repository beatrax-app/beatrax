<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Services\UserCountry;
use Modules\Core\Public\Support\DerivedRowId;
use Modules\Core\Public\Support\Lang;
use Modules\Tax\Internal\Exceptions\DuplicateTaxCategoryNameException;
use Modules\Tax\Public\Services\TaxCategoryWriter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class TaxSettingsSection extends Component
{
    use DispatchesToast;

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
        } catch (DuplicateTaxCategoryNameException $duplicate) {
            $this->addError = $duplicate->getMessage();
        } catch (\RuntimeException) {
            // Its sibling carries a developer's sentence in one language — the
            // row went in and its id could not be read back — and printing that
            // under the name box would also blame a name that is fine.
            $this->addError = Lang::get('tax::messages.errors.category_not_saved');
        }
    }

    public function renameCategory(
        int|string $categoryId,
        string $name,
        CurrentUser $currentUser,
        TaxCategoryWriter $writer,
    ): void {
        $this->renameError = '';

        try {
            $writer->rename($currentUser->user()->id, DerivedRowId::fromWire($categoryId), $name);
        } catch (NotFoundHttpException) {
            $this->reportCategoryGone();
        } catch (DuplicateTaxCategoryNameException|\InvalidArgumentException $e) {
            $this->renameError = $e->getMessage();
        } catch (\RuntimeException) {
            // The siblings of those two are a driver's sentence and a
            // developer's, both in one language and one of them carrying the
            // statement it failed on. Neither belongs under a name box.
            $this->renameError = Lang::get('tax::messages.errors.category_not_saved');
        }
    }

    public function archiveCategory(
        int|string $categoryId,
        CurrentUser $currentUser,
        TaxCategoryWriter $writer,
    ): void {
        try {
            $writer->archive($currentUser->user()->id, DerivedRowId::fromWire($categoryId));
        } catch (NotFoundHttpException) {
            $this->reportCategoryGone();
        }
    }

    public function unarchiveCategory(
        int|string $categoryId,
        CurrentUser $currentUser,
        TaxCategoryWriter $writer,
    ): void {
        try {
            $writer->unarchive($currentUser->user()->id, DerivedRowId::fromWire($categoryId));
        } catch (NotFoundHttpException) {
            $this->reportCategoryGone();
        }
    }

    // A row that vanished between render and click leaves the list on the very
    // next render, so archiving reads as having worked and restoring reads as
    // having worked — on the one action that did not happen. A toast rather
    // than $renameError: the field the inline error sits under is gone too.
    private function reportCategoryGone(): void
    {
        $this->toast(Lang::get('tax::settings.category_gone'));
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
