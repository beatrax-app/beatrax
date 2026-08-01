<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Lang;
use Modules\Forecasting\Internal\Support\AmountStringParser;
use Modules\Forecasting\Public\Actions\SetAccountForecastBuffer;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AccountBufferEditor extends Component
{
    use DispatchesToast;

    public int $accountId = 0;

    public string $accountName = '';

    public ?int $currentBufferMinor = null;

    public string $currency = 'EUR';

    public string $bufferInput = '';

    public ?string $errorMessage = null;

    public function mount(
        int $accountId,
        ?int $currentBufferMinor,
        string $currency,
        string $accountName,
        CurrentUser $currentUser,
        DatabaseManager $db,
    ): void {
        // Cross-user safety: refuse to mount with another user's account id
        // — the query below scopes the lookup to the current user, so a
        // tampered accountId prop resolves to no row and throws 404
        // instead of leaking another user's buffer.
        $row = $db->connection()->table('accounts')
            ->where('id', $accountId)
            ->where('user_id', $currentUser->user()->id)
            ->first(['id']);
        if ($row === null) {
            throw new NotFoundHttpException('Account not found.');
        }

        $this->accountId = $accountId;
        $this->currentBufferMinor = $currentBufferMinor;
        $this->currency = $currency;
        $this->accountName = $accountName;
        $this->bufferInput = $currentBufferMinor !== null
            ? number_format($currentBufferMinor / 100, 2, ',', '.')
            : '';
    }

    public function save(CurrentUser $currentUser, SetAccountForecastBuffer $action): void
    {
        $this->errorMessage = null;

        $minor = $this->parseInputToMinor($this->bufferInput);
        if ($minor === false) {
            $this->errorMessage = Lang::get('forecasting::buffer.errors.non_negative');

            return;
        }

        try {
            ($action)($this->accountId, $currentUser->user(), $minor);
        } catch (InvalidArgumentException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->currentBufferMinor = $minor;
        $this->dispatch('buffer-editor:saved');
        $this->toast(Lang::get('forecasting::buffer.toast.updated'));
    }

    public function clear(CurrentUser $currentUser, SetAccountForecastBuffer $action): void
    {
        $this->errorMessage = null;
        ($action)($this->accountId, $currentUser->user(), null);
        $this->currentBufferMinor = null;
        $this->bufferInput = '';
        $this->dispatch('buffer-editor:saved');
        $this->toast(Lang::get('forecasting::buffer.toast.updated'));
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('forecasting::livewire.account-buffer-editor', [
            'accountId' => $this->accountId,
            'accountName' => $this->accountName,
            'currency' => $this->currency,
            'currentBufferMinor' => $this->currentBufferMinor,
            'bufferInput' => $this->bufferInput,
            'errorMessage' => $this->errorMessage,
        ]);
    }

    private function parseInputToMinor(string $input): int|false
    {
        if (trim($input) === '') {
            return 0;
        }

        try {
            return AmountStringParser::toMinor($input, allowNegative: false);
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
