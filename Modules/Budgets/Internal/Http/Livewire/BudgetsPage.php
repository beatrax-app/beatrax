<?php

declare(strict_types=1);

namespace Modules\Budgets\Internal\Http\Livewire;

use Modules\Ledger\Public\ValueObjects\MoneyInput;
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
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Services\PeriodQuery;

/**
 * @link ../../../../../.docs/features/budgets/architecture.md
 */
final class BudgetsPage extends Component
{
    use DispatchesToast;

    private const PERIOD_DATE_FORMAT = 'Y-m-d';

    // Mirrors CarryoverQuery::FUTURE_HORIZON_PERIODS -- the nav bound
    // must agree with the fold's own forward walk limit.
    private const FUTURE_HORIZON_PERIODS = 12;

    public ?string $periodStartStr = null;

    /** @var array<int, string> decimal strings keyed by category id */
    public array $assignedInputs = [];

    /** @var array<int, string> whole-number notify-threshold strings keyed by category id */
    public array $thresholdInputs = [];

    /** @var array<int, string> inline per-row threshold validation errors keyed by category id */
    public array $thresholdErrors = [];

    // Move-money modal state, backing the per-row move-money modal opened
    // via openMove() and submitted via moveMoney().
    public int $moveFromCategoryId = 0;

    public string $moveToCategoryId = '';

    public string $moveAmount = '';

    public string $moveMemo = '';

    public string $moveError = '';

    // -------------------------------------------------------------------
    // Month navigation
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
    // Assign-every-euro grid
    // -------------------------------------------------------------------

    // An empty or literal-zero input tombstones the row; any other valid
    // amount upserts it (over-assignment is never rejected). EnvelopeWriter
    // re-validates $categoryId server-side regardless of what the rendered
    // grid displayed.
    public function setAssigned(CurrentUser $currentUser, EnvelopeWriter $writer, PeriodQuery $periods, int $categoryId): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $raw = trim($this->assignedInputs[$categoryId] ?? '');
        $minor = $this->parseAssignedAmount($writer, $raw);

        if ($minor === null) {
            $this->toast(Lang::get('budgets::messages.notices.invalid_amount'));

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

    // A bounds-check (1..200, whole numbers only) runs HERE before the
    // writer is called: an out-of-range or non-numeric value sets an
    // inline per-row error string rather than throwing, in defence-in-depth
    // alongside the writer's own server-side check.
    public function setNotifyThreshold(CurrentUser $currentUser, EnvelopeWriter $writer, int $categoryId): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        unset($this->thresholdErrors[$categoryId]);

        $raw = trim($this->thresholdInputs[$categoryId] ?? '');

        if ($raw === '') {
            $threshold = null;
        } elseif (ctype_digit($raw)
            && (int) $raw >= EnvelopeWriter::MIN_NOTIFY_THRESHOLD_PERCENT
            && (int) $raw <= EnvelopeWriter::MAX_NOTIFY_THRESHOLD_PERCENT) {
            $threshold = (int) $raw;
        } else {
            $this->thresholdErrors[$categoryId] = Lang::get('budgets::messages.notices.threshold_range');

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

    // Silent no-op on an invalid mode or an inaccessible category id -- the
    // <select> only ever offers the two valid values, but the server never
    // trusts that.
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

    // Offered only while the selected period has zero assignments and the
    // prior period has some ($showCopyBanner in render()).
    public function copyLastMonth(CurrentUser $currentUser, EnvelopeWriter $writer, PeriodQuery $periods): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $selected = $this->resolvePeriod($periods);
        $writer->copyFromPeriod($currentUser->user(), $periods->previous($selected), $selected);
        $this->assignedInputs = [];
        $this->toast(Lang::get('budgets::messages.notices.copied_last_month'));
    }

    // -------------------------------------------------------------------
    // Move money between envelopes
    // -------------------------------------------------------------------

    // The from-envelope is resolved for display in render() rather than
    // trusted from any client-controlled value.
    public function openMove(int $fromCategoryId): void
    {
        $this->moveFromCategoryId = $fromCategoryId;
        $this->moveToCategoryId = '';
        $this->moveAmount = '';
        $this->moveMemo = '';
        $this->moveError = '';
        $this->dispatch('modal-show', name: 'envelope-move');
    }

    // Deliberately NO "insufficient balance" catch: a move that takes the
    // source envelope negative always succeeds (see architecture.md).
    public function moveMoney(CurrentUser $currentUser, EnvelopeWriter $writer, PeriodQuery $periods): void
    {
        $this->moveError = '';

        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $toCategoryId = $this->moveToCategoryId !== '' ? (int) $this->moveToCategoryId : 0;
        $minor = $writer->parseAmount($this->moveAmount);

        if ($toCategoryId <= 0 || $minor === null) {
            $this->moveError = $toCategoryId <= 0
                ? Lang::get('budgets::messages.notices.choose_envelope')
                : Lang::get('budgets::messages.notices.amount_positive');

            return;
        }

        $period = $this->resolvePeriod($periods);
        $memo = trim($this->moveMemo) !== '' ? $this->moveMemo : null;

        try {
            $writer->move($currentUser->user(), $this->moveFromCategoryId, $toCategoryId, $period->start, $minor, $memo);

            $this->moveFromCategoryId = 0;
            $this->moveToCategoryId = '';
            $this->moveAmount = '';
            $this->moveMemo = '';
            $this->dispatch('modal-close', name: 'envelope-move');
            $this->toast(Lang::get('budgets::messages.notices.money_moved'));
        } catch (InvalidArgumentException $e) {
            $this->moveError = $e->getMessage();
        } catch (\RuntimeException) {
            // A non-validation writer failure must surface as a calm inline
            // error, never escape as an unhandled 500.
            $this->moveError = Lang::get('budgets::messages.notices.move_failed');
        }
    }

    public function undoMove(CurrentUser $currentUser, EnvelopeWriter $writer, int $moveId): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $writer->undoMove($currentUser->user(), $moveId);
        $this->toast(Lang::get('budgets::messages.notices.move_undone'));
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
            $view->extends('layouts.app', ['title' => Lang::get('budgets::messages.page.title').' · Beatrax']);

            return $view;
        }

        $user = $currentUser->user();
        $selected = $this->resolvePeriod($periods);

        $fold = $carryover->forUserAndPeriod($user, $selected);
        /** @var array<int, EnvelopeRow> $rows */
        $rows = $fold['rows'];
        $toBudgetMinor = $fold['toBudgetMinor'];
        $overspentCount = $fold['overspentCount'];

        $this->seedAssignedInputs($rows);
        $this->seedThresholdInputs($db, $user->id, $rows);

        $genesis = $this->genesisPeriod($user, $db, $periods);
        $max = $this->maxPeriod($periods);

        $canGoPrevious = $genesis !== null && $selected->start->greaterThan($genesis->start);
        $canGoNext = $selected->start->lessThan($max->start);

        $hasAssignmentsSelected = $this->periodHasAssignments($db, $user->id, $selected);
        $previousPeriod = $periods->previous($selected);
        $priorIsWithinGenesis = $genesis === null || ! $previousPeriod->start->lessThan($genesis->start);
        $priorHasAssignments = $priorIsWithinGenesis && $this->periodHasAssignments($db, $user->id, $previousPeriod);
        $showCopyBanner = ! $hasAssignmentsSelected && $priorHasAssignments;

        // Move-money modal data: from-envelope is resolved server-side
        // from the already-validated fold rows, never trusted from the
        // client-controlled moveFromCategoryId directly.
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

        // Batched into ONE query for all fold categories rather than one
        // query per envelope on every render.
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
        $view->extends('layouts.app', ['title' => Lang::get('budgets::messages.page.title').' · Beatrax']);

        return $view;
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    // Validates $periodStartStr strictly against Y-m-d; on any mismatch or
    // parse failure, falls back to the current period and clears the
    // property so a bad value cannot survive the round-trip.
    private function resolvePeriod(PeriodQuery $periods): Period
    {
        if ($this->periodStartStr === null) {
            return $periods->current();
        }

        $parsed = CarbonImmutable::createFromFormat(self::PERIOD_DATE_FORMAT, $this->periodStartStr);
        if ($parsed === null || $parsed->format(self::PERIOD_DATE_FORMAT) !== $this->periodStartStr) {
            $this->periodStartStr = null;

            return $periods->current();
        }

        return $periods->containing($parsed);
    }

    // Mirrors CarryoverQuery::genesisAnchorFor()'s raw, explicitly-scoped
    // read, kept independent here since the nav-bounds calculation is
    // this component's own concern, not something CarryoverQuery exposes
    // publicly.
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

    // current + FUTURE_HORIZON_PERIODS -- the forward nav bound; must agree
    // with the fold's own forward walk limit.
    private function maxPeriod(PeriodQuery $periods): Period
    {
        $max = $periods->current();
        for ($i = 0; $i < self::FUTURE_HORIZON_PERIODS; $i++) {
            $max = $periods->next($max);
        }

        return $max;
    }

    /**
     * @return array<int, int> category_id => percent, for envelopes with an
     *                         explicit threshold only (absent otherwise)
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

    /**
     * @param  array<int, EnvelopeRow>  $rows
     */
    private function seedAssignedInputs(array $rows): void
    {
        foreach ($rows as $categoryId => $row) {
            if (! array_key_exists($categoryId, $this->assignedInputs)) {
                $this->assignedInputs[$categoryId] = $row->assignedMinor > 0
                    ? $this->minorToDecimal($row->assignedMinor)
                    : '';
            }
        }
    }

    /**
     * @param  array<int, EnvelopeRow>  $rows
     */
    private function seedThresholdInputs(DatabaseManager $db, int $userId, array $rows): void
    {
        // Seeded from EXPLICIT stored values only: an envelope with no
        // explicit threshold stays blank so the placeholder default shows,
        // rather than pre-filling the resolved value as if user-set.
        $explicitThresholds = $this->explicitThresholds($db, $userId);
        foreach (array_keys($rows) as $categoryId) {
            if (! array_key_exists($categoryId, $this->thresholdInputs)) {
                $this->thresholdInputs[$categoryId] = array_key_exists($categoryId, $explicitThresholds)
                    ? (string) $explicitThresholds[$categoryId]
                    : '';
            }
        }
    }

    private function periodHasAssignments(DatabaseManager $db, int $userId, Period $period): bool
    {
        return $db->connection()
            ->table('envelope_assignments')
            ->where('user_id', $userId)
            ->where('period_start', $period->start->toDateString())
            ->exists();
    }

    // EnvelopeWriter::parseAmount() only accepts strictly-positive amounts
    // (null for both an unparsable string AND a literal "0"), so an empty
    // or explicit-zero input is special-cased here into an explicit
    // tombstone rather than surfacing as an "invalid amount" error.
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

    // Through the shared input formatter, so the field reads in the same
    // convention as the figures beside it. A dot here put "50.00" next to
    // "€ 50,00" in one row; tryToMinor() accepts both on the way back.
    private function minorToDecimal(int $minor): string
    {
        return MoneyInput::formatMinor($minor);
    }

    private function toast(string $message): void
    {
        $this->toastWithUndo($message, undoAction: '', undoPayload: null);
    }
}
