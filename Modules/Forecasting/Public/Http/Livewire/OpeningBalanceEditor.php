<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Lang;
use Modules\Forecasting\Internal\Exceptions\OpeningBalanceDivergenceWarning;
use Modules\Forecasting\Internal\Support\AmountStringParser;
use Modules\Forecasting\Public\Actions\SetAccountOpeningBalance;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\ValueObjects\MoneyInput;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class OpeningBalanceEditor extends Component
{
    use DispatchesToast;

    // Locked: mount() checks this id against the reader's own accounts, and
    // every save writes to whatever it holds afterwards. Unlocked, the checked
    // id and the written id were allowed to be different accounts.
    #[Locked]
    public int $accountId = 0;

    public string $accountName = '';

    public string $accountKind = '';

    public ?int $currentOpeningMinor = null;

    public ?string $currentAsOfDate = null;

    // Locked: the account's own denomination, and parseInputToMinor() reads
    // the typed figure at its scale. Unlocked, a payload naming JPY made
    // "150" on a EUR account persist as 150 minor rather than 15000.
    #[Locked]
    public string $currency = Currency::Eur->value;

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
            ? MoneyInput::formatMinor($currentOpeningMinor, $currency)
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

        // An empty box is the reader taking the override back, not a typo. The
        // figure outranks the import-detected baseline everywhere a balance is
        // read, so its absence has to be expressible; a typed 0 is a value and
        // stays one.
        $minor = null;
        if (trim($this->openingInput) !== '') {
            $parsed = $this->parseInputToMinor($this->openingInput);
            if ($parsed === false) {
                $this->errorMessage = Lang::get('forecasting::opening_balance.errors.invalid_number');

                return;
            }
            $minor = $parsed;
        }

        $asOf = ($minor === null || trim($this->asOfInput) === '') ? null : $this->asOfInput;

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
        $this->toast(Lang::get($minor === null
            ? 'forecasting::opening_balance.toast.removed'
            : 'forecasting::opening_balance.toast.updated'));
    }

    // Saving an empty box does the same thing; this is the affordance that
    // says so, and the Blade draws it only while there is an override to take
    // back.
    public function remove(CurrentUser $currentUser, SetAccountOpeningBalance $action): void
    {
        $this->openingInput = '';
        $this->asOfInput = '';
        $this->save($currentUser, $action);
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
        $this->toast(Lang::get('forecasting::opening_balance.toast.updated'));
    }

    public function useBeatraxsNumber(): void
    {
        if ($this->beatraxsNumberMinor === null) {
            return;
        }
        $this->openingInput = MoneyInput::formatMinor($this->beatraxsNumberMinor, $this->currency);
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
            'currentOpeningMinor' => $this->currentOpeningMinor,
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
            return AmountStringParser::toMinor($input, $this->currency);
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
