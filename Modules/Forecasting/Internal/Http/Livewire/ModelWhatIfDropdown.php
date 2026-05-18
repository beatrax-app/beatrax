<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Redirector;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Forecasting\Public\Actions\CreateAmountChangeScenarioForSeries;
use Modules\Forecasting\Public\Actions\CreateCancellationScenarioForSeries;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Phase 8 `/recurring/series/{id}` "Model what-if ↗" popover.
 *
 * Triggers the two launchpad Public Actions that pre-seed a fresh
 * scenario for the current series and redirect to
 * `/forecast?scenarioId={new}`. Two modes:
 *   - `modelCancellation` — invokes
 *     `CreateCancellationScenarioForSeries` and redirects immediately.
 *   - `modelAmountChange` — opens an inline amount input pre-populated
 *     with the current series amount; on Save, parses the input,
 *     invokes `CreateAmountChangeScenarioForSeries`, and redirects.
 *
 * Mount-time cross-user 404 — refuses to render with another user's
 * series id.
 */
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
        $this->newAmountInput = number_format(abs($this->currentAmountMinor) / 100, 2, ',', '.');
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
        $this->newAmountInput = number_format(abs($this->currentAmountMinor) / 100, 2, ',', '.');
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
            $this->errorMessage = 'Amount must be a positive number.';

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
        $trimmed = trim($input);
        if ($trimmed === '') {
            return null;
        }
        $normalised = str_replace([' ', '.'], ['', ''], $trimmed);
        $normalised = str_replace(',', '.', $normalised);
        if (! is_numeric($normalised)) {
            return null;
        }
        $value = (float) $normalised;
        if ($value <= 0) {
            return null;
        }

        return (int) round($value * 100);
    }
}
