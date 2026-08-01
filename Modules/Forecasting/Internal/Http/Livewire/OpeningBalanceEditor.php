<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Forecasting\Internal\Support\AmountStringParser;
use Modules\Forecasting\Public\Actions\SetAccountOpeningBalance;
use Modules\Forecasting\Public\Exceptions\OpeningBalanceDivergenceWarning;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @link ../../../../../.docs/features/forecasting/architecture.md
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
        // Cross-user safety: refuse to mount with another user's account id
        // — the query below scopes the lookup to the current user, so a
        // tampered accountId prop resolves to no row and throws 404
        // instead of leaking another user's opening balance.
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
            $this->errorMessage = Lang::get('forecasting::opening_balance.errors.invalid_number');

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
        $this->dispatch('toast', message: Lang::get('forecasting::opening_balance.toast.updated'));
    }

    public function useMyNumber(CurrentUser $currentUser, SetAccountOpeningBalance $action): void
    {
        $this->errorMessage = null;

        $minor = $this->parseInputToMinor($this->openingInput);
        if ($minor === false) {
            $this->errorMessage = Lang::get('forecasting::opening_balance.errors.invalid_number');

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
        $this->dispatch('toast', message: Lang::get('forecasting::opening_balance.toast.updated'));
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
