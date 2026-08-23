<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Internal\Actions\SetAccountCurrency;
use Modules\Ledger\Internal\Exceptions\AccountCurrencyRelabelWarning;
use Modules\Ledger\Public\Enums\Currency;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @link ../../../../../.docs/features/ledger/architecture.md#changing-an-accounts-currency
 */
final class AccountCurrencyEditor extends Component
{
    use DispatchesToast;

    public int $accountId = 0;

    public string $accountName = '';

    public string $currency = Currency::Eur->value;

    // What the row holds, kept apart from the bound $currency so cancelling
    // the warning can put the <select> back to it. The two differ only
    // between choosing a currency and answering for it.
    public string $storedCurrency = Currency::Eur->value;

    public ?string $errorMessage = null;

    public bool $showingRelabelBanner = false;

    public ?int $relabelBaselineMinor = null;

    /** @var array<string, int> */
    public array $relabelLines = [];

    public bool $saved = false;

    public function mount(
        int $accountId,
        string $accountName,
        string $currency,
        CurrentUser $currentUser,
        DatabaseManager $db,
    ): void {
        // Cross-user safety, as the opening-balance editor beside it does: the
        // lookup is scoped to the current user, so a tampered accountId prop
        // resolves to no row and throws 404 rather than relabelling somebody
        // else's account.
        $row = $db->connection()->table('accounts')
            ->where('id', $accountId)
            ->where('user_id', $currentUser->user()->id)
            ->first(['id']);
        if ($row === null) {
            throw new NotFoundHttpException('Account not found.');
        }

        $this->accountId = $accountId;
        $this->accountName = $accountName;
        $this->currency = $currency;
        $this->storedCurrency = $currency;
    }

    public function save(CurrentUser $currentUser, SetAccountCurrency $action): void
    {
        $this->apply($currentUser, $action, allowRelabel: false);
    }

    public function relabelAnyway(CurrentUser $currentUser, SetAccountCurrency $action): void
    {
        $this->apply($currentUser, $action, allowRelabel: true);
    }

    // The warning is answerable both ways, so the reader can put the <select>
    // back without reloading the page and without a second write.
    public function keepCurrent(): void
    {
        $this->currency = $this->storedCurrency;
        $this->clearWarning();
    }

    public function render(ViewFactory $views, DatabaseManager $db): View
    {
        return $views->make('ledger::livewire.account-currency-editor', [
            'accountId' => $this->accountId,
            'accountName' => $this->accountName,
            'currency' => $this->currency,
            'storedCurrency' => $this->storedCurrency,
            'currencyOptions' => $this->currencyOptions($db),
            'errorMessage' => $this->errorMessage,
            'showingRelabelBanner' => $this->showingRelabelBanner,
            'relabelBaselineMinor' => $this->relabelBaselineMinor,
            'relabelLines' => $this->relabelLines,
            'saved' => $this->saved,
        ]);
    }

    private function apply(CurrentUser $currentUser, SetAccountCurrency $action, bool $allowRelabel): void
    {
        $this->errorMessage = null;
        $this->saved = false;

        try {
            ($action)($this->accountId, $currentUser->user(), $this->currency, $allowRelabel);
        } catch (AccountCurrencyRelabelWarning $warning) {
            $this->relabelBaselineMinor = $warning->baselineMinor;
            $this->relabelLines = $warning->linesByCurrency;
            $this->showingRelabelBanner = true;

            return;
        } catch (InvalidArgumentException $invalid) {
            $this->currency = $this->storedCurrency;
            $this->errorMessage = $invalid->getMessage();
            $this->clearWarning();

            return;
        }

        $this->storedCurrency = $this->currency;
        $this->saved = true;
        $this->clearWarning();
        $this->toast(Lang::get('ledger::account_currency.toast.updated', [
            'name' => $this->accountName,
            'currency' => $this->currency,
        ]));
    }

    private function clearWarning(): void
    {
        $this->showingRelabelBanner = false;
        $this->relabelBaselineMinor = null;
        $this->relabelLines = [];
    }

    /**
     * @return array<string, string>
     */
    private function currencyOptions(DatabaseManager $db): array
    {
        // The same reference table the base-currency picker offers, not the
        // Currency enum: the enum names only the codes the code itself writes
        // as literals, and the selectable set is data the install seeds.
        $options = [];
        foreach ($db->connection()->table('currencies')->orderBy('code')->get(['code', 'name']) as $row) {
            /** @var stdClass $row */
            $code = is_string($row->code) ? $row->code : '';
            if ($code !== '') {
                $options[$code] = is_string($row->name) ? $row->name : $code;
            }
        }

        return $options;
    }
}
