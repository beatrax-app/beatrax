<?php

declare(strict_types=1);

namespace Modules\Budgets\Internal\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Livewire\Component;
use Modules\Budgets\Public\Dto\EnvelopeRow;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Budgets\Public\Services\EnvelopeBalanceQuery;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Services\PeriodQuery;

/**
 * `/budgets` — the rebuilt zero-based envelope grid (Req 3/5/6/7/8/12).
 *
 * Replaces the flat, period-agnostic `category_budgets` ceiling list with a
 * live assign-every-euro grid sourced entirely from `CarryoverQuery`'s
 * genesis-to-target fold: per-envelope assigned/spent/available, a sticky
 * "Ready to assign" header (green ≥ 0 / red < 0, never blocking — Req 8),
 * month navigation bounded at the user's envelope-activation genesis and
 * current+12 (Req 7), a per-row overspend-mode toggle, a "Copy last month"
 * auto-fill offered only when the selected month has zero assignments and a
 * prior month has some (Req 6), and a per-row move-money modal with a
 * per-envelope recent-moves + undo list (Req 5).
 *
 * Method-parameter DI on every action and on render() — constructor
 * injection is banned on Livewire `Component` subclasses by
 * phpstan-strict-rules (mirrors PotsPage/GoalsPage/Dashboard). Every action
 * guards `CurrentUser` at the top before any write, and every client-
 * supplied category id is re-validated server-side by `EnvelopeWriter`
 * (T-13.2-07-01) — the rendered grid/move `<select>` is never treated as
 * pre-authorization.
 *
 * State:
 *  - `periodStartStr` — client-controlled anchor for the selected period;
 *    always re-validated through `resolvePeriod()` (mirrors Dashboard's
 *    identical contract) so a malformed value never reaches
 *    `CarbonImmutable::parse()`.
 *  - `assignedInputs` — decimal-string inline editor state keyed by category
 *    id, seeded from the current fold on render() and cleared whenever the
 *    selected period changes or a bulk write (copy-last-month) occurs, so a
 *    stale value from a different period can never leak into the new one.
 *  - `moveFromCategoryId` / `moveToCategoryId` / `moveAmount` / `moveMemo` /
 *    `moveError` — the move-money modal's form state (Req 5, D-19).
 */
final class BudgetsPage extends Component
{
    private const PERIOD_DATE_FORMAT = 'Y-m-d';

    /** Mirrors CarryoverQuery::FUTURE_HORIZON_PERIODS (D-12c) — the nav
     *  bound must agree with the fold's own forward walk limit. */
    private const FUTURE_HORIZON_PERIODS = 12;

    /** Client-controlled selected-period anchor; always re-validated. */
    public ?string $periodStartStr = null;

    /** @var array<int, string> decimal strings keyed by category id */
    public array $assignedInputs = [];

    /** @var array<int, string> whole-number notify-threshold strings keyed by category id (D-20) */
    public array $thresholdInputs = [];

    /** @var array<int, string> inline per-row threshold validation errors keyed by category id */
    public array $thresholdErrors = [];

    // Move-money modal state (Req 5, D-19)
    public int $moveFromCategoryId = 0;

    public string $moveToCategoryId = '';

    public string $moveAmount = '';

    public string $moveMemo = '';

    public string $moveError = '';

    // -------------------------------------------------------------------
    // Month navigation (Req 7, D-20)
    // -------------------------------------------------------------------

    public function prevPeriod(CurrentUser $currentUser, PeriodQuery $periods, DatabaseManager $db): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $selected = $this->resolvePeriod($periods);
        $genesis = $this->genesisPeriod($currentUser->user(), $db, $periods);

        if ($genesis === null || ! $selected->start->greaterThan($genesis->start)) {
            return;
        }

        $this->periodStartStr = $periods->previous($selected)->start->toDateString();
        $this->assignedInputs = [];
    }

    public function nextPeriod(CurrentUser $currentUser, PeriodQuery $periods): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $selected = $this->resolvePeriod($periods);
        $max = $this->maxPeriod($periods);

        if (! $selected->start->lessThan($max->start)) {
            return;
        }

        $this->periodStartStr = $periods->next($selected)->start->toDateString();
        $this->assignedInputs = [];
    }

    // -------------------------------------------------------------------
    // Assign-every-euro grid (Req 3, D-18)
    // -------------------------------------------------------------------

    /**
     * Commits the inline assigned-cell editor for one envelope (wire:blur /
     * wire:keydown.enter). An empty or literal-zero input tombstones the row
     * (D-06); any other valid amount upserts it (over-assignment is NEVER
     * rejected — Req 8). `EnvelopeWriter` re-validates `$categoryId`
     * server-side regardless of what the rendered grid displayed
     * (T-13.2-07-01).
     */
    public function setAssigned(CurrentUser $currentUser, EnvelopeWriter $writer, PeriodQuery $periods, int $categoryId): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $raw = trim($this->assignedInputs[$categoryId] ?? '');
        $minor = $this->parseAssignedAmount($writer, $raw);

        if ($minor === null) {
            $this->toast('Enter a valid amount.');

            return;
        }

        $period = $this->resolvePeriod($periods);

        try {
            $writer->setAssigned($currentUser->user(), $categoryId, $period->start, $minor);
        } catch (InvalidArgumentException $e) {
            $this->toast($e->getMessage());

            return;
        }

        if ($minor === 0) {
            unset($this->assignedInputs[$categoryId]);
        } else {
            $this->assignedInputs[$categoryId] = $this->minorToDecimal($minor);
        }
    }

    /**
     * Commits the per-envelope over-budget notify-threshold control (D-20,
     * Req 6) via `EnvelopeWriter::setNotifyThreshold()`. An empty input clears
     * the explicit threshold back to the default (null). A bounds-check
     * (`1..200`, whole numbers only) runs HERE before the writer is called —
     * an out-of-range or non-numeric value sets an inline per-row error string
     * rather than throwing (T-18-04, defence in depth with the writer's own
     * server-side check). The writer re-validates `$categoryId` server-side
     * (IDOR) and scopes the write to the current user (T-18-05).
     */
    public function setNotifyThreshold(CurrentUser $currentUser, EnvelopeWriter $writer, int $categoryId): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        unset($this->thresholdErrors[$categoryId]);

        $raw = trim($this->thresholdInputs[$categoryId] ?? '');

        if ($raw === '') {
            $threshold = null;
        } elseif (ctype_digit($raw)) {
            $threshold = (int) $raw;
            if ($threshold < 1 || $threshold > 200) {
                $this->thresholdErrors[$categoryId] = 'Enter a whole number between 1 and 200.';

                return;
            }
        } else {
            $this->thresholdErrors[$categoryId] = 'Enter a whole number between 1 and 200.';

            return;
        }

        try {
            $writer->setNotifyThreshold($currentUser->user(), $categoryId, $threshold);
        } catch (InvalidArgumentException $e) {
            $this->thresholdErrors[$categoryId] = $e->getMessage();

            return;
        }

        // Reflect the stored value back: an explicit value shows as itself; a
        // cleared threshold empties the field so the placeholder default shows.
        $this->thresholdInputs[$categoryId] = $threshold === null ? '' : (string) $threshold;
    }

    /**
     * Toggles the per-envelope overspend-carry behavior (D-23). Silent no-op
     * on an invalid mode or an inaccessible category id — the `<select>` only
     * ever offers the two valid values, but the server never trusts that.
     */
    public function setOverspendMode(CurrentUser $currentUser, EnvelopeWriter $writer, int $categoryId, string $mode): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        try {
            $writer->setOverspendMode($currentUser->user(), $categoryId, $mode);
        } catch (InvalidArgumentException $e) {
            $this->toast($e->getMessage());
        }
    }

    /**
     * Reproduces the prior period's assigned amounts into the selected
     * period (Req 6, D-22) — offered only while the selected period has zero
     * assignments and the prior period has some (`$showCopyBanner` in
     * render()).
     */
    public function copyLastMonth(CurrentUser $currentUser, EnvelopeWriter $writer, PeriodQuery $periods): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $selected = $this->resolvePeriod($periods);
        $writer->copyFromPeriod($currentUser->user(), $periods->previous($selected), $selected);
        $this->assignedInputs = [];
        $this->toast('Copied last month’s plan.');
    }

    // -------------------------------------------------------------------
    // Move money between envelopes (Req 5, D-19)
    // -------------------------------------------------------------------

    /**
     * Opens the move-money modal for `$fromCategoryId`, resetting the rest
     * of the form state (mirrors `PotsPage::movePot`'s operation-modal reset
     * shape). The from-envelope is resolved for display in render() rather
     * than trusted from any client-controlled value.
     */
    public function openMove(int $fromCategoryId): void
    {
        $this->moveFromCategoryId = $fromCategoryId;
        $this->moveToCategoryId = '';
        $this->moveAmount = '';
        $this->moveMemo = '';
        $this->moveError = '';
        $this->dispatch('modal-show', name: 'envelope-move');
    }

    /**
     * Submits the move-money modal. Maps `EnvelopeWriter::move()`'s
     * `InvalidArgumentException` to the two inline field errors the
     * UI-SPEC's copywriting contract defines — there is deliberately NO
     * "insufficient balance" catch (Req 8 / Pitfall 1): a move that takes
     * the source envelope negative always succeeds.
     */
    public function moveMoney(CurrentUser $currentUser, EnvelopeWriter $writer, PeriodQuery $periods): void
    {
        $this->moveError = '';

        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $toCategoryId = $this->moveToCategoryId !== '' ? (int) $this->moveToCategoryId : 0;
        if ($toCategoryId <= 0) {
            $this->moveError = 'Choose an envelope to move money to.';

            return;
        }

        $minor = $writer->parseAmount($this->moveAmount);
        if ($minor === null) {
            $this->moveError = 'Enter an amount greater than zero.';

            return;
        }

        $period = $this->resolvePeriod($periods);
        $memo = trim($this->moveMemo) !== '' ? $this->moveMemo : null;

        try {
            $writer->move($currentUser->user(), $this->moveFromCategoryId, $toCategoryId, $period->start, $minor, $memo);
        } catch (InvalidArgumentException $e) {
            $this->moveError = $e->getMessage();

            return;
        } catch (\RuntimeException) {
            // WR-06: a non-validation writer failure (e.g. the paired-row write
            // guard in EnvelopeWriter::move()) must surface as a calm inline
            // error, never escape as an unhandled 500.
            $this->moveError = 'Could not complete the move — please try again.';

            return;
        }

        $this->moveFromCategoryId = 0;
        $this->moveToCategoryId = '';
        $this->moveAmount = '';
        $this->moveMemo = '';
        $this->dispatch('modal-close', name: 'envelope-move');
        $this->toast('Money moved.');
    }

    /**
     * Reverses a move via the per-envelope recent-moves list (D-07/D-19):
     * `EnvelopeWriter::undoMove()` hard-deletes both paired rows. A foreign
     * or missing move id is a silent no-op (mirrors `PotWriter::archive`'s
     * cross-user handling).
     */
    public function undoMove(CurrentUser $currentUser, EnvelopeWriter $writer, int $moveId): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $writer->undoMove($currentUser->user(), $moveId);
        $this->toast('Move undone.');
    }

    // -------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------

    public function render(
        CurrentUser $currentUser,
        CarryoverQuery $carryover,
        EnvelopeBalanceQuery $balances,
        PeriodQuery $periods,
        DatabaseManager $db,
        ViewFactory $views,
    ): View {
        if (! $currentUser->isAuthenticated()) {
            $view = $views->make('budgets::livewire.budgets-page', [
                'rows' => [],
                'toBudgetMinor' => 0,
                'overspentCount' => 0,
                'period' => $periods->current(),
                'canGoPrevious' => false,
                'canGoNext' => false,
                'showCopyBanner' => false,
                'moveFromCategory' => null,
                'moveDestinations' => [],
                'recentMoves' => [],
                'defaultNotifyThreshold' => CarryoverQuery::DEFAULT_NOTIFY_THRESHOLD_PERCENT,
            ]);

            /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
            $view->extends('layouts.app', ['title' => 'Budgets · beatrax']);

            return $view;
        }

        $user = $currentUser->user();
        $selected = $this->resolvePeriod($periods);

        $fold = $carryover->forUserAndPeriod($user, $selected);
        /** @var array<int, EnvelopeRow> $rows */
        $rows = $fold['rows'];
        $toBudgetMinor = $fold['toBudgetMinor'];
        $overspentCount = $fold['overspentCount'];

        foreach ($rows as $categoryId => $row) {
            if (! array_key_exists($categoryId, $this->assignedInputs)) {
                $this->assignedInputs[$categoryId] = $row->assignedMinor > 0
                    ? $this->minorToDecimal($row->assignedMinor)
                    : '';
            }
        }

        // Seed the notify-threshold inputs from the EXPLICIT stored values
        // only (D-20). Envelopes with no explicit threshold stay blank so the
        // placeholder default (DEFAULT_NOTIFY_THRESHOLD_PERCENT) is what shows,
        // rather than pre-filling the resolved 90 as if it were user-set.
        $explicitThresholds = $this->explicitThresholds($db, $user->id);
        foreach ($rows as $categoryId => $row) {
            if (! array_key_exists($categoryId, $this->thresholdInputs)) {
                $this->thresholdInputs[$categoryId] = array_key_exists($categoryId, $explicitThresholds)
                    ? (string) $explicitThresholds[$categoryId]
                    : '';
            }
        }

        $genesis = $this->genesisPeriod($user, $db, $periods);
        $max = $this->maxPeriod($periods);

        $canGoPrevious = $genesis !== null && $selected->start->greaterThan($genesis->start);
        $canGoNext = $selected->start->lessThan($max->start);

        $hasAssignmentsSelected = $this->periodHasAssignments($db, $user->id, $selected);
        $previousPeriod = $periods->previous($selected);
        $priorIsWithinGenesis = $genesis === null || ! $previousPeriod->start->lessThan($genesis->start);
        $priorHasAssignments = $priorIsWithinGenesis && $this->periodHasAssignments($db, $user->id, $previousPeriod);
        $showCopyBanner = ! $hasAssignmentsSelected && $priorHasAssignments;

        // Move-money modal data (Req 5, D-19): from-envelope is resolved
        // server-side from the already-validated fold rows, never trusted
        // from the client-controlled moveFromCategoryId directly.
        $moveFromCategory = null;
        $moveDestinations = [];
        if ($this->moveFromCategoryId !== 0 && array_key_exists($this->moveFromCategoryId, $rows)) {
            $moveFromCategory = $rows[$this->moveFromCategoryId];
            $moveDestinations = array_filter(
                $rows,
                fn (int $categoryId): bool => $categoryId !== $this->moveFromCategoryId,
                ARRAY_FILTER_USE_KEY,
            );
        }

        // Per-envelope recent-moves + undo (D-19). Batched into ONE query for
        // all fold categories (IN-01) rather than one query per envelope on
        // every render.
        $recentMoves = $balances->recentMovesForCategories($user->id, array_keys($rows), $selected);

        $view = $views->make('budgets::livewire.budgets-page', [
            'rows' => $rows,
            'toBudgetMinor' => $toBudgetMinor,
            'overspentCount' => $overspentCount,
            'period' => $selected,
            'canGoPrevious' => $canGoPrevious,
            'canGoNext' => $canGoNext,
            'showCopyBanner' => $showCopyBanner,
            'moveFromCategory' => $moveFromCategory,
            'moveDestinations' => $moveDestinations,
            'recentMoves' => $recentMoves,
            'defaultNotifyThreshold' => CarryoverQuery::DEFAULT_NOTIFY_THRESHOLD_PERCENT,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Budgets · beatrax']);

        return $view;
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    /**
     * Resolves the displayed period. Validates `$periodStartStr` strictly
     * against `Y-m-d`; on any mismatch or parse failure, falls back to the
     * current period and clears the property so a bad value cannot survive
     * the round-trip (identical contract to `Dashboard::resolvePeriod()`).
     */
    private function resolvePeriod(PeriodQuery $periods): Period
    {
        if ($this->periodStartStr === null) {
            return $periods->current();
        }

        $parsed = CarbonImmutable::createFromFormat(self::PERIOD_DATE_FORMAT, $this->periodStartStr);
        if ($parsed === null) {
            $this->periodStartStr = null;

            return $periods->current();
        }

        if ($parsed->format(self::PERIOD_DATE_FORMAT) !== $this->periodStartStr) {
            $this->periodStartStr = null;

            return $periods->current();
        }

        return $periods->containing($parsed);
    }

    /**
     * The user's envelope-activation genesis anchor, as a Period, or null
     * when the user has not yet been activated (D-12b) — mirrors
     * `CarryoverQuery::genesisAnchorFor()`'s raw, explicitly-scoped read
     * (kept independent here since the nav-bounds calculation is this
     * component's own concern, not something `CarryoverQuery` exposes
     * publicly).
     */
    private function genesisPeriod(User $user, DatabaseManager $db, PeriodQuery $periods): ?Period
    {
        $raw = $db->connection()->table('users')->where('id', $user->id)->value('envelope_activated_at');

        if ($raw === null || $raw === '' || ! is_string($raw)) {
            return null;
        }

        try {
            return $periods->containing(CarbonImmutable::parse($raw));
        } catch (\Throwable) {
            return null;
        }
    }

    /** current + FUTURE_HORIZON_PERIODS (D-12c) — the forward nav bound. */
    private function maxPeriod(PeriodQuery $periods): Period
    {
        $max = $periods->current();
        for ($i = 0; $i < self::FUTURE_HORIZON_PERIODS; $i++) {
            $max = $periods->next($max);
        }

        return $max;
    }

    /**
     * The user's EXPLICIT per-envelope notify thresholds (D-20) as
     * category_id => percent, reading only rows where `threshold_percent` is
     * set. Envelopes with no explicit value are absent from the map (they fall
     * back to the placeholder default in the view). Explicitly `user_id`-scoped
     * — never trusts a global scope for this read.
     *
     * @return array<int, int>
     */
    private function explicitThresholds(DatabaseManager $db, int $userId): array
    {
        $rows = $db->connection()
            ->table('envelope_settings')
            ->where('user_id', $userId)
            ->whereNotNull('threshold_percent')
            ->get(['category_id', 'threshold_percent']);

        $map = [];
        foreach ($rows as $row) {
            $categoryId = is_numeric($row->category_id) ? (int) $row->category_id : 0;
            $percent = is_numeric($row->threshold_percent) ? (int) $row->threshold_percent : 0;
            if ($categoryId > 0 && $percent > 0) {
                $map[$categoryId] = $percent;
            }
        }

        return $map;
    }

    private function periodHasAssignments(DatabaseManager $db, int $userId, Period $period): bool
    {
        return $db->connection()
            ->table('envelope_assignments')
            ->where('user_id', $userId)
            ->where('period_start', $period->start->toDateString())
            ->exists();
    }

    /**
     * Parses an inline assigned-cell string into a non-negative minor
     * amount, or null when genuinely invalid. `EnvelopeWriter::parseAmount()`
     * only accepts strictly-positive amounts (it returns null for both an
     * unparsable string AND a literal "0"), so an empty or explicit-zero
     * input is special-cased here into an explicit tombstone (D-06) rather
     * than surfacing as an "invalid amount" error.
     */
    private function parseAssignedAmount(EnvelopeWriter $writer, string $raw): ?int
    {
        if ($raw === '') {
            return 0;
        }

        $minor = $writer->parseAmount($raw);
        if ($minor !== null) {
            return $minor;
        }

        $normalised = str_replace([' ', "\u{00A0}"], '', $raw);
        $normalised = str_replace(',', '.', $normalised);

        return is_numeric($normalised) && (float) $normalised === 0.0 ? 0 : null;
    }

    private function minorToDecimal(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }

    private function toast(string $message): void
    {
        $this->dispatch('toast', message: $message, undoAction: '', undoPayload: null);
    }
}
