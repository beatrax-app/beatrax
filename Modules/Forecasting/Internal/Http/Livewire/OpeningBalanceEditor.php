<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Forecasting\Internal\Support\AmountStringParser;
use Modules\Forecasting\Public\Actions\SetAccountOpeningBalance;
use Modules\Forecasting\Public\Exceptions\OpeningBalanceDivergenceWarning;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Per-account opening-balance + as-of-date inline editor for the
 * `/settings` Forecasting section.
 *
 * Mounts inside each account row in the Forecasting section. The
 * editor is INLINE (no popover wrapper) — unlike AccountBufferEditor
 * which is popover-style for the /forecast chart header. The Save
 * button on the parent SettingsPage drives the persistence; this
 * component dispatches `forecast-settings:saved` so the parent can
 * stitch the per-account commits together.
 *
 * Soft-warning flow:
 *   1. User edits the opening balance + as-of date.
 *   2. Save invokes SetAccountOpeningBalance with `allowDivergence=false`.
 *   3. If divergence > €500 the Action throws
 *      `OpeningBalanceDivergenceWarning`; the editor catches it,
 *      pops the soft-warning banner, and keeps the form open with
 *      `divergenceDiffMinor` set.
 *   4. The user clicks `Use my number` to commit with
 *      `allowDivergence=true`, OR clicks `Use beatrax's number` to
 *      replace the input with the computed sum-of-transactions and
 *      manually re-save.
 *
 * Constructor-injection is banned on Livewire `Component` subclasses
 * by phpstan-strict-rules; collaborators arrive on mount / save /
 * useBeatraxsNumber / useMyNumber / render via parameter DI.
 */
final class OpeningBalanceEditor extends Component
{
    public int $accountId = 0;

    public string $accountName = '';

    public string $accountKind = '';

    public ?int $currentOpeningMinor = null;

    public ?string $currentAsOfDate = null;

    public string $currency = 'EUR';

    public string $openingInput = '';

    public string $asOfInput = '';

    public ?string $errorMessage = null;

    public ?int $divergenceDiffMinor = null;

    public ?int $beatraxsNumberMinor = null;

    public bool $showingDivergenceBanner = false;

    public bool $saved = false;

    public function mount(
        int $accountId,
        ?int $currentOpeningMinor,
        ?string $currentAsOfDate,
        string $currency,
        string $accountName,
        string $accountKind,
        CurrentUser $currentUser,
        DatabaseManager $db,
    ): void {
        // Cross-user safety: refuse to mount with another user's account id.
        $row = $db->connection()->table('accounts')
            ->where('id', $accountId)
            ->where('user_id', $currentUser->user()->id)
            ->first(['id']);
        if ($row === null) {
            throw new NotFoundHttpException('Account not found.');
        }

        $this->accountId = $accountId;
        $this->accountName = $accountName;
        $this->accountKind = $accountKind;
        $this->currentOpeningMinor = $currentOpeningMinor;
        $this->currentAsOfDate = $currentAsOfDate;
        $this->currency = $currency;

        $this->openingInput = $currentOpeningMinor !== null
            ? number_format($currentOpeningMinor / 100, 2, ',', '.')
            : '';
        $this->asOfInput = $currentAsOfDate ?? '';
    }

    public function save(CurrentUser $currentUser, SetAccountOpeningBalance $action): void
    {
        $this->errorMessage = null;
        $this->showingDivergenceBanner = false;
        $this->divergenceDiffMinor = null;
        $this->beatraxsNumberMinor = null;
        $this->saved = false;

        $minor = $this->parseInputToMinor($this->openingInput);
        if ($minor === false) {
            $this->errorMessage = 'Opening balance must be a valid number.';

            return;
        }

        $asOf = trim($this->asOfInput) === '' ? null : $this->asOfInput;

        try {
            ($action)($this->accountId, $currentUser->user(), $minor, $asOf, allowDivergence: false);
        } catch (OpeningBalanceDivergenceWarning $w) {
            $this->divergenceDiffMinor = $w->diffMinor;
            $this->beatraxsNumberMinor = $w->sumOfTransactionsMinor;
            $this->showingDivergenceBanner = true;

            return;
        } catch (InvalidArgumentException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->currentOpeningMinor = $minor;
        $this->currentAsOfDate = $asOf;
        $this->saved = true;
        $this->dispatch('forecast-settings:saved', accountId: $this->accountId);
        $this->dispatch('toast', message: 'Opening balance updated.');
    }

    public function useMyNumber(CurrentUser $currentUser, SetAccountOpeningBalance $action): void
    {
        $this->errorMessage = null;

        $minor = $this->parseInputToMinor($this->openingInput);
        if ($minor === false) {
            $this->errorMessage = 'Opening balance must be a valid number.';

            return;
        }

        $asOf = trim($this->asOfInput) === '' ? null : $this->asOfInput;

        try {
            ($action)($this->accountId, $currentUser->user(), $minor, $asOf, allowDivergence: true);
        } catch (InvalidArgumentException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->currentOpeningMinor = $minor;
        $this->currentAsOfDate = $asOf;
        $this->showingDivergenceBanner = false;
        $this->divergenceDiffMinor = null;
        $this->beatraxsNumberMinor = null;
        $this->saved = true;
        $this->dispatch('forecast-settings:saved', accountId: $this->accountId);
        $this->dispatch('toast', message: 'Opening balance updated.');
    }

    public function useBeatraxsNumber(): void
    {
        if ($this->beatraxsNumberMinor === null) {
            return;
        }
        $this->openingInput = number_format($this->beatraxsNumberMinor / 100, 2, ',', '.');
        $this->showingDivergenceBanner = false;
        $this->divergenceDiffMinor = null;
        $this->errorMessage = null;
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('forecasting::livewire.opening-balance-editor', [
            'accountId' => $this->accountId,
            'accountName' => $this->accountName,
            'accountKind' => $this->accountKind,
            'currency' => $this->currency,
            'openingInput' => $this->openingInput,
            'asOfInput' => $this->asOfInput,
            'errorMessage' => $this->errorMessage,
            'showingDivergenceBanner' => $this->showingDivergenceBanner,
            'divergenceDiffMinor' => $this->divergenceDiffMinor,
            'beatraxsNumberMinor' => $this->beatraxsNumberMinor,
            'saved' => $this->saved,
        ]);
    }

    /**
     * Parse the user input (locale-aware "500,00" / "500.00" / "500"
     * including a negative sign for credit-card / overdrawn accounts)
     * to BIGINT minor units. Returns false on any non-numeric or empty
     * input.
     */
    private function parseInputToMinor(string $input): int|false
    {
        if (trim($input) === '') {
            return false;
        }

        try {
            return AmountStringParser::toMinor($input);
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
