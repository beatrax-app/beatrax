<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;

/**
 * Livewire child component the first-import step mounts once per
 * detected account (and once per account that needs manual entry).
 * Each card owns its own state machine — Detected / Conflict / Editing
 * / Confirmed / manual-entry — and emits `starting-balance.confirmed`
 * back to the parent step whenever the user accepts a value. The
 * parent aggregates every emitted payload into a per-account
 * `$balanceConfirmations` map that the commit-everything button later
 * writes to the `accounts.starting_balance_*` columns inside the same
 * DB transaction that confirms the stashed ImportRuns.
 *
 * State machine:
 *
 *   - `detected` — one detector fired with a non-zero value (the
 *     common case). The card shows the pre-filled value, a Confirm
 *     pill, and a quiet Edit affordance.
 *   - `conflict` — two detectors disagreed on the same account. The
 *     card shows a radio-button list of alternative candidates with
 *     the earliest-date one pre-selected.
 *   - `editing` — the user clicked Edit (or started a manual entry).
 *     The card shows inline number + date inputs with Cancel + Save.
 *   - `confirmed` — the user accepted a value; the card collapses to
 *     a single-line summary with a Change link.
 *   - `manual-entry` — no detector fired for this account; the card
 *     mounts in an "editing"-shaped state with empty inputs and a
 *     lede explaining the auto-detect miss.
 *
 * The user-id boundary is enforced when `save()` reads the
 * `transactions.posted_at` column for the earliest-transaction
 * warning — the query carries an explicit `where('user_id', ...)`
 * even though the BelongsToUser global scope would catch it on an
 * Eloquent query (this is a raw `DB::table()` read for tight control
 * over the one-column SELECT).
 *
 * Per the project DI-only rule, every collaborator is method-DI'd on
 * `save()` — Livewire forbids constructor DI on Component subclasses.
 */
final class StartingBalanceCard extends Component
{
    /**
     * Minimum and maximum minor-unit value the validator accepts on
     * `save()`. ±€10M caps a typo or tampered-frontend cent value at
     * a sane bound.
     */
    private const MIN_BALANCE_MINOR = -1_000_000_000_00;

    private const MAX_BALANCE_MINOR = 1_000_000_000_00;

    /**
     * The accounts.id row this card's confirmation will write to. Set
     * at mount; the parent step looks up the right value via
     * DetectStartingBalancesQuery (or via the account list itself for
     * the manual-entry case).
     */
    public int $accountId = 0;

    /**
     * Human-readable label for the account ("ASN account", "ICS card",
     * "PayPal balance"). Rendered in the H3 and in the confirmed
     * single-line summary.
     */
    public string $accountLabel = '';

    /**
     * Short identifier rendered inside the .funding-tag mono pill on
     * the card eyebrow — typically "NL18 ASNB · 4321" (IBAN tail-4 +
     * bank code) but free-form so the PayPal / wallet case can render
     * "PAYPAL · wallet".
     */
    public string $accountShort = '';

    /**
     * ISO-4217 currency code for the account. Drives the currency
     * symbol/suffix in the rendered numeric value.
     */
    public string $currency = 'EUR';

    /**
     * Detector verdict for this account, in minor units. Null when
     * the detector returned no candidate (manual-entry case).
     */
    public ?int $detectedMinor = null;

    /**
     * Detector verdict date in ISO `YYYY-MM-DD` format. Null on
     * manual-entry.
     */
    public ?string $detectedDate = null;

    /**
     * User-edited value while the card is in `editing` state. Bound
     * to a numeric input via `wire:model.live`.
     */
    public ?int $editedMinor = null;

    /**
     * User-edited date while the card is in `editing` state. Bound
     * to a date input via `wire:model.live`.
     */
    public ?string $editedDate = null;

    /**
     * Current state machine slot — one of `detected`, `conflict`,
     * `editing`, `confirmed`, `manual-entry`. Mounted by the parent;
     * mutated by the card's own action methods.
     */
    public string $state = 'detected';

    /**
     * True once the user has accepted a value (either via `confirm`,
     * `save`, or `pickConflictCandidate`). Drives the emerald left-
     * stroke + ✓ glyph on the card chrome.
     */
    public bool $isConfirmed = false;

    /**
     * Warning message populated on `save()` when the accepted date is
     * later than the account's earliest imported transaction date.
     * Empty string when no warning applies.
     */
    public string $dateWarning = '';

    /**
     * Inline validation error surfaced beneath an offending input
     * (out-of-range amount, unparseable date, future date). Empty
     * string when no error.
     */
    public string $validationError = '';

    /**
     * Alternative candidates the user can pick between when the card
     * is in `conflict` state. Each entry carries the minor + date +
     * source-format label rendered alongside the radio button.
     *
     * @var list<array{minor: int, date: string, sourceLabel: string}>
     */
    public array $alternativeCandidates = [];

    /**
     * Index into `$alternativeCandidates` the user has selected on
     * the conflict variant. Defaults to 0 (the earliest-date
     * candidate, which wins the tie-break on date alone).
     */
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

    /**
     * Accept the detector's value as-is. Used from the Detected
     * state's primary "Confirm" pill — the card collapses to the
     * confirmed single-line summary and emits a payload carrying the
     * detector's own minor + date.
     */
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

    /**
     * Switch the card from Detected (or Confirmed) into Editing
     * state. Copies the detected values into the edited fields so the
     * inputs render with the existing value as a starting point.
     */
    public function startEdit(): void
    {
        $this->validationError = '';
        $this->dateWarning = '';
        $this->editedMinor = $this->editedMinor ?? $this->detectedMinor;
        $this->editedDate = $this->editedDate ?? $this->detectedDate;
        $this->state = 'editing';
    }

    /**
     * Discard an in-progress edit and revert to the prior state. When
     * a detector verdict exists the card snaps back to Detected;
     * otherwise (manual-entry) the inputs remain visible and just the
     * validation/error state is cleared.
     */
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

    /**
     * Validate the user's edits and accept them. Range-checks the
     * minor amount, requires a parseable ISO date that is not in the
     * future, optionally surfaces a warning when the date is later
     * than the account's earliest imported transaction, then
     * dispatches `starting-balance.confirmed` regardless of the
     * warning — the warning is informational only.
     */
    public function save(
        DatabaseManager $db,
        CurrentUser $currentUser,
    ): void {
        if ($this->accountId <= 0) {
            $this->validationError = 'Account not set. Reload the wizard.';

            return;
        }

        $this->validationError = '';
        $this->dateWarning = '';

        $minor = $this->editedMinor;
        $date = $this->editedDate;

        if ($minor === null) {
            $this->validationError = 'Enter a valid amount.';

            return;
        }
        if ($minor < self::MIN_BALANCE_MINOR || $minor > self::MAX_BALANCE_MINOR) {
            $this->validationError = 'Enter an amount between -€10M and €10M.';

            return;
        }
        if ($date === null || $date === '') {
            $this->validationError = 'Pick a date.';

            return;
        }
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            $this->validationError = 'Pick a valid date.';

            return;
        }
        if ($timestamp > time()) {
            $this->validationError = 'Starting balance date cannot be in the future.';

            return;
        }

        $user = $currentUser->user();

        $earliest = $db->connection()
            ->table('transactions')
            ->where('user_id', $user->id)
            ->where('account_id', $this->accountId)
            ->orderBy('posted_at', 'asc')
            ->value('posted_at');

        if (is_string($earliest) && $earliest !== '' && $date > substr($earliest, 0, 10)) {
            $this->dateWarning = sprintf(
                'This is later than your first imported transaction (%s). Your dashboard may show transactions before this date.',
                substr($earliest, 0, 10),
            );
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

    /**
     * Conflict variant — pick one of the alternative candidates the
     * detector aggregator surfaced for this account and emit it as
     * the confirmation payload.
     */
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
