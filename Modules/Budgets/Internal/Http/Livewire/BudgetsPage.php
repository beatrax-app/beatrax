<?php

declare(strict_types=1);

namespace Modules\Budgets\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Budgets\Public\Dto\EnvelopeRow;
use Modules\Budgets\Public\Enums\OverspendMode;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Budgets\Public\Services\EnvelopeBalanceQuery;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Exceptions\IdReadBackFailedException;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\DerivedRowId;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\ValueObjects\MoneyInput;

final class BudgetsPage extends Component
{
    use DispatchesToast;

    // Locked because only prevPeriod(), nextPeriod() and render() clamp it to
    // [genesis, now + horizon]; setAssigned(), moveMoney() and copyLastMonth()
    // resolve it straight. Unlocked, a forged "2099-06-15" wrote a real
    // envelope_assignments row in a month the fold bounds out of every view.
    #[Locked]
    public ?string $periodStartStr = null;

    /** @var array<int, string> decimal strings keyed by category id */
    public array $assignedInputs = [];

    /** @var array<int, string> whole-number notify-threshold strings keyed by category id */
    public array $thresholdInputs = [];

    /** @var array<int, string> inline per-row threshold validation errors keyed by category id */
    public array $thresholdErrors = [];

    public int $moveFromCategoryId = 0;

    public string $moveToCategoryId = '';

    public string $moveAmount = '';

    public string $moveMemo = '';

    public string $moveError = '';

    public function prevPeriod(CurrentUser $currentUser, PeriodQuery $periods, CarryoverQuery $carryover): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $resolved = $periods->resolveAnchor($this->periodStartStr);
        $this->periodStartStr = $resolved->isoDate;
        $selected = $resolved->period;
        $genesis = $carryover->genesisPeriodFor($currentUser->user());

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

        $resolved = $periods->resolveAnchor($this->periodStartStr);
        $this->periodStartStr = $resolved->isoDate;
        $selected = $resolved->period;
        $max = $this->maxPeriod($periods);

        if (! $selected->start->lessThan($max->start)) {
            return;
        }

        $this->periodStartStr = $periods->next($selected)->start->toDateString();
        $this->assignedInputs = [];
    }

    // An empty or literal-zero input tombstones the row; anything else upserts,
    // and over-assignment is never rejected.
    public function setAssigned(CurrentUser $currentUser, EnvelopeWriter $writer, PeriodQuery $periods, BaseCurrency $baseCurrency, int|string $categoryId): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $categoryId = DerivedRowId::fromWire($categoryId);

        $currency = $baseCurrency->forUser($currentUser->user());
        $raw = trim($this->assignedInputs[$categoryId] ?? '');
        $minor = $this->parseAssignedAmount($writer, $raw, $currency);

        if ($minor === null) {
            // Drop the rejected text so the next render reseeds the cell from
            // what is actually stored. Left in place it showed a budget that
            // was never written, and the toast that says so does not survive
            // the glance that follows it.
            unset($this->assignedInputs[$categoryId]);

            $this->toast(Lang::get('budgets::messages.notices.invalid_amount'));

            return;
        }

        $resolved = $periods->resolveAnchor($this->periodStartStr);
        $this->periodStartStr = $resolved->isoDate;
        $period = $resolved->period;

        $refused = false;

        try {
            $writer->setAssigned($currentUser->user(), $categoryId, $period->start, $minor);
        } catch (InvalidArgumentException $e) {
            $this->toast($e->getMessage());
            $refused = true;
        } catch (IdReadBackFailedException) {
            // The write rolled back with the read that could not name its own
            // row, so the cell is reseeded from what is actually stored rather
            // than left showing a figure nothing holds.
            unset($this->assignedInputs[$categoryId]);
            $this->toast(Lang::get('core::errors.not_saved'));
            $refused = true;
        }

        if ($refused) {
            return;
        }

        if ($minor === 0) {
            unset($this->assignedInputs[$categoryId]);
        } else {
            $this->assignedInputs[$categoryId] = $this->minorToDecimal($minor, $currency);
        }
    }

    // Bounds-checked here as well as in the writer so an out-of-range entry is an
    // inline per-row error, not a throw the grid has to render around.
    public function setNotifyThreshold(CurrentUser $currentUser, EnvelopeWriter $writer, int|string $categoryId): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $categoryId = DerivedRowId::fromWire($categoryId);

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

        $this->thresholdInputs[$categoryId] = $threshold === null ? '' : (string) $threshold;
    }

    public function setOverspendMode(CurrentUser $currentUser, EnvelopeWriter $writer, int|string $categoryId, string $mode): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $categoryId = DerivedRowId::fromWire($categoryId);

        $parsed = OverspendMode::tryFrom($mode);

        if ($parsed === null) {
            $this->toast(Lang::get('budgets::messages.errors.invalid_overspend_mode'));

            return;
        }

        try {
            $writer->setOverspendMode($currentUser->user(), $categoryId, $parsed);
        } catch (InvalidArgumentException $e) {
            $this->toast($e->getMessage());
        }
    }

    // Reachable only while $showCopyBanner holds: nothing assigned this period,
    // something assigned last.
    public function copyLastMonth(CurrentUser $currentUser, EnvelopeWriter $writer, PeriodQuery $periods): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $resolved = $periods->resolveAnchor($this->periodStartStr);
        $this->periodStartStr = $resolved->isoDate;
        $selected = $resolved->period;
        try {
            $writer->copyFromPeriod($currentUser->user(), $periods->previous($selected), $selected);
        } catch (IdReadBackFailedException) {
            $this->toast(Lang::get('core::errors.not_saved'));

            return;
        }

        $this->assignedInputs = [];
        $this->toast(Lang::get('budgets::messages.notices.copied_last_month'));
    }

    public function openMove(int|string $fromCategoryId): void
    {
        $this->moveFromCategoryId = DerivedRowId::fromWire($fromCategoryId);
        $this->moveToCategoryId = '';
        $this->moveAmount = '';
        $this->moveMemo = '';
        $this->moveError = '';

        // No `modal-show` dispatch: which surface opens is a viewport decision
        // the two Move buttons already make, and announcing it here opened the
        // desktop modal over the phone's sheet. The modal then owned the hit
        // test, so a tap on the sheet's own button dismissed the modal.
    }

    // No "insufficient balance" catch by design: a move that takes the source
    // envelope negative succeeds.
    public function moveMoney(CurrentUser $currentUser, EnvelopeWriter $writer, PeriodQuery $periods, BaseCurrency $baseCurrency): void
    {
        $this->moveError = '';

        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $toCategoryId = $this->moveToCategoryId !== '' ? (int) $this->moveToCategoryId : 0;
        $minor = $writer->parseAmount($this->moveAmount, $baseCurrency->forUser($currentUser->user()));

        if ($toCategoryId <= 0 || $minor === null) {
            $this->moveError = $toCategoryId <= 0
                ? Lang::get('budgets::messages.notices.choose_envelope')
                : Lang::get('budgets::messages.notices.amount_positive');

            return;
        }

        $resolved = $periods->resolveAnchor($this->periodStartStr);
        $this->periodStartStr = $resolved->isoDate;
        $period = $resolved->period;
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
            $this->moveError = Lang::get('budgets::messages.notices.move_failed');
        }
    }

    // Quoted on the way out and read back through fromWire(): a move id is
    // derived from the move's own identity and runs past 2^53, which a JSON
    // number literal rounds before the server ever sees it.
    public function undoMove(CurrentUser $currentUser, EnvelopeWriter $writer, int|string $moveId): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $writer->undoMove($currentUser->user(), DerivedRowId::fromWire($moveId));
        $this->toast(Lang::get('budgets::messages.notices.move_undone'));
    }

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
        $resolved = $periods->resolveAnchor($this->periodStartStr);

        // The month the fold will answer for, not the one the anchor named.
        // Emptying the earliest month moves genesis past a selection already on
        // screen, and the grid then drew the next month's figures under the
        // heading, the moves list and the copy banner of the month before it.
        $selected = $carryover->boundedPeriodFor($user, $resolved->period);
        $this->periodStartStr = $selected->start->equalTo($resolved->period->start)
            ? $resolved->isoDate
            : $selected->start->toDateString();

        $fold = $carryover->forUserAndPeriod($user, $selected);
        /** @var array<int, EnvelopeRow> $rows */
        $rows = $fold['rows'];
        $toBudgetMinor = $fold['toBudgetMinor'];
        $overspentCount = $fold['overspentCount'];

        $this->seedAssignedInputs($rows);
        $this->seedThresholdInputs($db, $user->id, $rows);

        $genesis = $carryover->genesisPeriodFor($user);
        $max = $this->maxPeriod($periods);

        $canGoPrevious = $genesis !== null && $selected->start->greaterThan($genesis->start);
        $canGoNext = $selected->start->lessThan($max->start);

        $hasAssignmentsSelected = $this->periodHasAssignments($db, $user->id, $selected);
        $previousPeriod = $periods->previous($selected);
        $priorIsWithinGenesis = $genesis === null || ! $previousPeriod->start->lessThan($genesis->start);
        $priorHasAssignments = $priorIsWithinGenesis && $this->periodHasAssignments($db, $user->id, $previousPeriod);
        $showCopyBanner = ! $hasAssignmentsSelected && $priorHasAssignments;

        // The from-envelope comes out of the already-validated fold rows; the
        // client-controlled moveFromCategoryId is only a key into them.
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

    private function maxPeriod(PeriodQuery $periods): Period
    {
        $max = $periods->current();
        for ($i = 0; $i < CarryoverQuery::FUTURE_HORIZON_PERIODS; $i++) {
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
                    ? $this->minorToDecimal($row->assignedMinor, $row->currency)
                    : '';
            }
        }
    }

    /**
     * @param  array<int, EnvelopeRow>  $rows
     */
    private function seedThresholdInputs(DatabaseManager $db, int $userId, array $rows): void
    {
        // Explicit stored values only: pre-filling the resolved default would read
        // back as user-set, and the next save would freeze it.
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

    // EnvelopeWriter::parseAmount() returns null for a literal "0" as well as for
    // junk, so zero is recovered here as a tombstone rather than reported invalid.
    private function parseAssignedAmount(EnvelopeWriter $writer, string $raw, ?string $currency): ?int
    {
        if ($raw === '') {
            return 0;
        }

        $minor = $writer->parseAmount($raw, $currency);
        if ($minor !== null) {
            return $minor;
        }

        return MoneyInput::tryToMinor($raw, $currency) === 0 ? 0 : null;
    }

    // A dot here put "50.00" next to "€ 50,00" in one row; the shared formatter
    // matches the figures beside it and tryToMinor() accepts both on the way back.
    private function minorToDecimal(int $minor, ?string $currency): string
    {
        return MoneyInput::formatMinor($minor, $currency);
    }
}
