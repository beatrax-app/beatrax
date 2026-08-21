<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Redirector;
use InvalidArgumentException;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Forecasting\Internal\Support\AmountStringParser;
use Modules\Forecasting\Public\Actions\CreateAmountChangeScenarioForSeries;
use Modules\Forecasting\Public\Actions\CreateCancellationScenarioForSeries;
use Modules\Ledger\Public\ValueObjects\MoneyInput;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ModelWhatIfDropdown extends Component
{
    public int $seriesId = 0;

    public string $seriesName = '';

    public int $currentAmountMinor = 0;

    public string $currency = 'EUR';

    public string $newAmountInput = '';

    public ?string $errorMessage = null;

    public string $mode = 'closed';

    public function mount(
        int $seriesId,
        CurrentUser $currentUser,
        RecurringSeriesQuery $seriesQuery,
    ): void {
        $series = $seriesQuery->forSeries($seriesId, $currentUser->user());
        if ($series === null) {
            throw new NotFoundHttpException('Recurring series not found.');
        }
        $this->seriesId = $seriesId;
        $this->seriesName = $series->displayNameOverride ?? $series->detectedName;
        $this->currentAmountMinor = $series->latestAmount->toMinor();
        $this->currency = $series->latestAmount->currency();
        $this->newAmountInput = MoneyInput::formatAbsMinor($this->currentAmountMinor);
    }

    public function openMenu(): void
    {
        $this->mode = 'menu';
        $this->errorMessage = null;
    }

    public function closeMenu(): void
    {
        $this->mode = 'closed';
        $this->errorMessage = null;
    }

    public function openAmountForm(): void
    {
        $this->mode = 'amount-form';
        $this->errorMessage = null;
        $this->newAmountInput = MoneyInput::formatAbsMinor($this->currentAmountMinor);
    }

    public function modelCancellation(
        CurrentUser $currentUser,
        CreateCancellationScenarioForSeries $action,
        Redirector $redirector,
    ): mixed {
        $newId = ($action)($this->seriesId, $currentUser->user());
        $this->mode = 'closed';

        return $redirector->to('/forecast?scenarioId='.$newId);
    }

    public function saveAmountChange(
        CurrentUser $currentUser,
        CreateAmountChangeScenarioForSeries $action,
        Redirector $redirector,
    ): mixed {
        $this->errorMessage = null;
        $minor = $this->parseAmountToMinor($this->newAmountInput);
        if ($minor === null) {
            $this->errorMessage = Lang::get('forecasting::scenario.errors.amount_positive');

            return null;
        }
        $newId = ($action)($this->seriesId, $currentUser->user(), $minor);
        $this->mode = 'closed';

        return $redirector->to('/forecast?scenarioId='.$newId);
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('forecasting::livewire.model-what-if-dropdown', [
            'seriesId' => $this->seriesId,
            'seriesName' => $this->seriesName,
            'currentAmountMinor' => $this->currentAmountMinor,
            'currency' => $this->currency,
            'newAmountInput' => $this->newAmountInput,
            'errorMessage' => $this->errorMessage,
            'mode' => $this->mode,
        ]);
    }

    private function parseAmountToMinor(string $input): ?int
    {
        try {
            $minor = AmountStringParser::toMinor($input, allowNegative: false, requireNonZero: true);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $minor;
    }
}
