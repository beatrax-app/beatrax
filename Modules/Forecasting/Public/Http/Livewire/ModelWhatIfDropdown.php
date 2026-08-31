<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Redirector;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Forecasting\Internal\Enums\ScenarioTemplate;
use Modules\Forecasting\Internal\Support\AmountStringParser;
use Modules\Forecasting\Public\Actions\CreateScenarioFromTemplate;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\ValueObjects\MoneyInput;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ModelWhatIfDropdown extends Component
{
    // Locked: mount() is the only writer and it names the series every action
    // then acts on. Unlocked, the wire chose which series a saved what-if was
    // written against while the screen still named the one the reader opened.
    #[Locked]
    public int $seriesId = 0;

    public string $seriesName = '';

    public int $currentAmountMinor = 0;

    // Locked: this is the series' own denomination, and saveAmountChange()
    // parses the typed figure at its scale. Unlocked, a payload naming JPY
    // made "150" on a EUR series persist as 150 minor rather than 15000.
    #[Locked]
    public string $currency = Currency::Eur->value;

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
        $this->newAmountInput = MoneyInput::formatAbsMinor($this->currentAmountMinor, $this->currency);
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
        $this->newAmountInput = MoneyInput::formatAbsMinor($this->currentAmountMinor, $this->currency);
    }

    public function modelCancellation(
        CurrentUser $currentUser,
        CreateScenarioFromTemplate $action,
        Redirector $redirector,
    ): mixed {
        $newId = ($action)(ScenarioTemplate::Cancel, $this->seriesId, $currentUser->user());
        $this->mode = 'closed';

        return $redirector->to('/forecast?scenarioId='.$newId);
    }

    public function saveAmountChange(
        CurrentUser $currentUser,
        CreateScenarioFromTemplate $action,
        Redirector $redirector,
    ): mixed {
        $this->errorMessage = null;
        $minor = $this->parseAmountToMinor($this->newAmountInput);
        if ($minor === null) {
            $this->errorMessage = Lang::get('forecasting::scenario.errors.amount_positive');

            return null;
        }
        $newId = ($action)(ScenarioTemplate::ChangeAmount, $this->seriesId, $currentUser->user(), $minor);
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
            $minor = AmountStringParser::toMinor($input, $this->currency, allowNegative: false, requireNonZero: true);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $minor;
    }
}
