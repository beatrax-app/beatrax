<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;

/**
 * @link ../../../../../.docs/features/onboarding/architecture.md
 */
final class StartingBalanceCard extends Component
{
    // ±€10M caps a typo or tampered-frontend cent value at a sane
    // bound.
    private const MIN_BALANCE_MINOR = -1_000_000_000_00;

    private const MAX_BALANCE_MINOR = 1_000_000_000_00;

    public int $accountId = 0;

    public string $accountLabel = '';

    // Rendered inside the .funding-tag mono pill on the card eyebrow —
    // typically "NL18 ASNB · 4321" but free-form for the PayPal/wallet case.
    public string $accountShort = '';

    public string $currency = 'EUR';

    // Null when the detector returned no candidate (the manual-entry
    // case).
    public ?int $detectedMinor = null;

    public ?string $detectedDate = null;

    // Bound to a numeric input via wire:model.live while the card is
    // editing.
    public ?int $editedMinor = null;

    public ?string $editedDate = null;

    public string $state = 'detected';

    public bool $isConfirmed = false;

    public string $dateWarning = '';

    public string $validationError = '';

    /** @var list<array{minor: int, date: string, sourceLabel: string}> */
    public array $alternativeCandidates = [];

    // Defaults to 0 — the earliest-date candidate, which wins the
    // tie-break on date alone.
    public int $selectedConflictIndex = 0;

    /**
     * @param  list<array{minor: int, date: string, sourceLabel: string}>  $alternativeCandidates
     */
    public function mount(
        int $accountId,
        string $accountLabel,
        string $accountShort,
        string $currency = 'EUR',
        ?int $detectedMinor = null,
        ?string $detectedDate = null,
        string $state = 'detected',
        array $alternativeCandidates = [],
    ): void {
        $this->accountId = $accountId;
        $this->accountLabel = $accountLabel;
        $this->accountShort = $accountShort;
        $this->currency = $currency;
        $this->detectedMinor = $detectedMinor;
        $this->detectedDate = $detectedDate;
        $this->state = $state;
        $this->editedMinor = $detectedMinor;
        $this->editedDate = $detectedDate;

        $this->alternativeCandidates = $alternativeCandidates;
    }

    public function confirm(): void
    {
        if ($this->accountId <= 0 || $this->detectedMinor === null || $this->detectedDate === null) {
            return;
        }

        $this->validationError = '';
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
    ): void {
        if ($this->accountId <= 0) {
            $this->validationError = Lang::get('onboarding::starting_balance.errors.account_not_set');

            return;
        }

        $this->validationError = '';
        $this->dateWarning = '';

        $minor = $this->editedMinor;
        $date = $this->editedDate;
        $error = $this->amountDateError($minor, $date);
        if ($error !== null || $minor === null || $date === null) {
            $this->validationError = $error ?? Lang::get('onboarding::starting_balance.errors.invalid_amount');

            return;
        }

        $this->persistConfirmation($db, $currentUser, $minor, $date);
    }

    // Returns the first validation error for the edited amount/date, or null
    // when both are valid. The match(true) keeps the ordering that surfaces
    // the most specific message the user can act on first.
    private function amountDateError(?int $minor, ?string $date): ?string
    {
        $timestamp = ($date === null || $date === '') ? false : strtotime($date);

        return match (true) {
            $minor === null => Lang::get('onboarding::starting_balance.errors.invalid_amount'),
            $minor < self::MIN_BALANCE_MINOR || $minor > self::MAX_BALANCE_MINOR => Lang::get('onboarding::starting_balance.errors.amount_range'),
            $date === null || $date === '' => Lang::get('onboarding::starting_balance.errors.pick_date'),
            $timestamp === false => Lang::get('onboarding::starting_balance.errors.pick_valid_date'),
            $timestamp > time() => Lang::get('onboarding::starting_balance.errors.future_date'),
            default => null,
        };
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

    public function pickConflictCandidate(int $candidateIndex): void
    {
        if ($this->accountId <= 0 || ! array_key_exists($candidateIndex, $this->alternativeCandidates)) {
            return;
        }

        $picked = $this->alternativeCandidates[$candidateIndex];
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
        return $views->make('onboarding::livewire.starting-balance-card');
    }
}
