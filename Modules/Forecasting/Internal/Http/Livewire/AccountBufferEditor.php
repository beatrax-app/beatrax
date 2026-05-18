<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Forecasting\Public\Actions\SetAccountForecastBuffer;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Per-account buffer-editor popover (inline on the /forecast per-account
 * chart header). Mounts with the account's current
 * `forecast_min_buffer_minor` and the account's metadata, exposes a
 * save/clear/cancel surface, and dispatches a re-projection on save
 * via the `SetAccountForecastBuffer` Public Action.
 *
 * Constructor injection is banned on Livewire `Component` subclasses
 * by phpstan-strict-rules; collaborators arrive on `mount()` / `save()`
 * / `clear()` / `render()` as parameter DI.
 *
 * Mirror analog: `Modules\DriftAlerts\Internal\Http\Livewire\DriftThresholdEditor`.
 */
final class AccountBufferEditor extends Component
{
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
        // Cross-user safety: refuse to mount with another user's account id.
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
            $this->errorMessage = 'Buffer must be zero or positive.';

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
        $this->dispatch('toast', message: 'Buffer updated.');
    }

    public function clear(CurrentUser $currentUser, SetAccountForecastBuffer $action): void
    {
        $this->errorMessage = null;
        ($action)($this->accountId, $currentUser->user(), null);
        $this->currentBufferMinor = null;
        $this->bufferInput = '';
        $this->dispatch('buffer-editor:saved');
        $this->dispatch('toast', message: 'Buffer updated.');
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

    /**
     * Parse the user input (locale-aware "500,00" / "500.00" / "500")
     * to BIGINT minor units. Returns false on any non-numeric input.
     */
    private function parseInputToMinor(string $input): int|false
    {
        $trimmed = trim($input);
        if ($trimmed === '') {
            return 0;
        }

        // Strip thousands separators (Dutch locale: '.'; English: ',').
        // We accept either as a thousands separator and treat the LAST
        // separator as the decimal mark for forgiveness.
        $normalised = str_replace([' '], '', $trimmed);
        $commaPos = strrpos($normalised, ',');
        $dotPos = strrpos($normalised, '.');
        if ($commaPos !== false && $dotPos !== false) {
            // Last one wins as the decimal separator.
            if ($commaPos > $dotPos) {
                $normalised = str_replace('.', '', $normalised);
                $normalised = str_replace(',', '.', $normalised);
            } else {
                $normalised = str_replace(',', '', $normalised);
            }
        } elseif ($commaPos !== false) {
            $normalised = str_replace(',', '.', $normalised);
        }

        if (! is_numeric($normalised)) {
            return false;
        }
        $float = (float) $normalised;
        if ($float < 0) {
            return false;
        }

        return (int) round($float * 100);
    }
}
