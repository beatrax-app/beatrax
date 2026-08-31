<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeDate;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Onboarding\Internal\Services\StartingBalanceRule;

final class StartingBalanceCard extends Component
{
    public int $accountId = 0;

    public string $accountLabel = '';

    public string $accountShort = '';

    // Assigned once from the account's default_currency and named by no
    // binding, while four places in the card and the rule behind it hand it to
    // Money as the denomination a figure is read at.
    #[Locked]
    public string $currency = '';

    #[Locked]
    public ?int $detectedMinor = null;

    #[Locked]
    public ?string $detectedDate = null;

    public ?int $editedMinor = null;

    public ?string $editedDate = null;

    public string $state = 'detected';

    public bool $isConfirmed = false;

    public string $dateWarning = '';

    public string $validationError = '';

    /** @var list<array{minor: int, date: string, sourceLabel: string}> */
    #[Locked]
    public array $alternativeCandidates = [];

    public int $selectedConflictIndex = 0;

    /**
     * @param  list<array{minor: int, date: string, sourceLabel: string}>  $alternativeCandidates
     */
    public function mount(
        int $accountId,
        string $accountLabel,
        string $accountShort,
        BaseCurrency $baseCurrency,
        ?string $currency = null,
        ?int $detectedMinor = null,
        ?string $detectedDate = null,
        string $state = 'detected',
        array $alternativeCandidates = [],
    ): void {
        $this->accountId = $accountId;
        $this->accountLabel = $accountLabel;
        $this->accountShort = $accountShort;
        $this->currency = $currency ?? $baseCurrency->code();
        $this->detectedMinor = $detectedMinor;
        $this->detectedDate = $detectedDate;
        $this->state = $state;
        $this->editedMinor = $detectedMinor;
        $this->editedDate = $detectedDate;

        $this->alternativeCandidates = $alternativeCandidates;
    }

    // The detector's own figures still face the rule save() applies to the
    // typed ones: a statement line can carry an amount out of range or a date
    // the parser refuses, and this dispatches straight to the writer.
    public function confirm(StartingBalanceRule $rule): void
    {
        if ($this->accountId <= 0 || $this->detectedMinor === null || $this->detectedDate === null) {
            return;
        }

        $this->validationError = '';
        $error = $rule->error($this->detectedMinor, $this->detectedDate, $this->currency);
        if ($error !== null) {
            $this->validationError = $error;

            return;
        }

        $this->editedMinor = $this->detectedMinor;
        $this->editedDate = $this->detectedDate;
        $this->state = 'confirmed';
        $this->isConfirmed = true;

        $this->dispatch(
            'starting-balance.confirmed',
            accountId: $this->accountId,
            minor: $this->detectedMinor,
            date: $this->detectedDate,
        );
    }

    public function startEdit(): void
    {
        $this->validationError = '';
        $this->dateWarning = '';
        $this->editedMinor = $this->editedMinor ?? $this->detectedMinor;
        $this->editedDate = $this->editedDate ?? $this->detectedDate;
        $this->state = 'editing';
    }

    public function cancelEdit(): void
    {
        $this->validationError = '';
        $this->dateWarning = '';

        if ($this->detectedMinor !== null && $this->detectedDate !== null) {
            $this->editedMinor = $this->detectedMinor;
            $this->editedDate = $this->detectedDate;
            $this->state = $this->isConfirmed ? 'confirmed' : 'detected';
        } else {
            $this->state = 'manual-entry';
        }
    }

    public function save(
        DatabaseManager $db,
        CurrentUser $currentUser,
        StartingBalanceRule $rule,
    ): void {
        if ($this->accountId <= 0) {
            $this->validationError = Lang::get('onboarding::starting_balance.errors.account_not_set');

            return;
        }

        $this->validationError = '';
        $this->dateWarning = '';

        $minor = $this->editedMinor;
        $date = $this->editedDate;
        $error = $rule->error($minor, $date, $this->currency);
        if ($error !== null || $minor === null || $date === null) {
            $this->validationError = $error ?? Lang::get('onboarding::starting_balance.errors.invalid_amount');

            return;
        }

        $this->persistConfirmation($db, $currentUser, $minor, $date);
    }

    private function persistConfirmation(DatabaseManager $db, CurrentUser $currentUser, int $minor, string $date): void
    {
        $user = $currentUser->user();

        $earliest = $db->connection()
            ->table('transactions')
            ->where('user_id', $user->id)
            ->where('account_id', $this->accountId)
            ->orderBy('posted_at', 'asc')
            ->value('posted_at');

        if (is_string($earliest) && $earliest !== '' && $date > substr($earliest, 0, 10)) {
            $this->dateWarning = Lang::get('onboarding::starting_balance.errors.date_warning', [
                'date' => substr($earliest, 0, 10),
            ]);
        }

        $this->state = 'confirmed';
        $this->isConfirmed = true;

        $this->dispatch(
            'starting-balance.confirmed',
            accountId: $this->accountId,
            minor: $minor,
            date: $date,
        );
    }

    public function pickConflictCandidate(int $candidateIndex, StartingBalanceRule $rule): void
    {
        if ($this->accountId <= 0 || ! array_key_exists($candidateIndex, $this->alternativeCandidates)) {
            return;
        }

        $this->validationError = '';
        $picked = $rule->confirmed($this->alternativeCandidates[$candidateIndex]);
        if ($picked === null) {
            $this->validationError = Lang::get('onboarding::starting_balance.errors.invalid_amount');

            return;
        }

        $this->selectedConflictIndex = $candidateIndex;
        $this->editedMinor = $picked['minor'];
        $this->editedDate = $picked['date'];
        $this->state = 'confirmed';
        $this->isConfirmed = true;

        $this->dispatch(
            'starting-balance.confirmed',
            accountId: $this->accountId,
            minor: $picked['minor'],
            date: $picked['date'],
        );
    }

    public function render(ViewFactory $views): View
    {
        $this->detectedDate = self::readableDayOrNull($this->detectedDate);
        $this->editedDate = self::readableDayOrNull($this->editedDate);
        $this->alternativeCandidates = self::readableCandidates($this->alternativeCandidates);

        return $views->make('onboarding::livewire.starting-balance-card');
    }

    // Both dates and the candidate list are public properties the view hands
    // straight to a date formatter, and that formatter throws on a string it
    // cannot read. Held to a real day rather than to a readable one: the
    // wizard writes Y-m-d, and 'tomorrow' formats cleanly into a wrong answer.
    private static function readableDayOrNull(?string $date): ?string
    {
        return $date !== null && SafeDate::dayOrNull($date) !== null ? $date : null;
    }

    /**
     * @param  array<array-key, mixed>  $candidates
     * @return list<array{minor: int, date: string, sourceLabel: string}>
     */
    private static function readableCandidates(array $candidates): array
    {
        $rows = [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $minor = $candidate['minor'] ?? null;
            $date = $candidate['date'] ?? null;
            $label = $candidate['sourceLabel'] ?? null;
            if (! is_int($minor) || ! is_string($date) || ! is_string($label) || SafeDate::dayOrNull($date) === null) {
                continue;
            }

            $rows[] = ['minor' => $minor, 'date' => $date, 'sourceLabel' => $label];
        }

        return $rows;
    }
}
