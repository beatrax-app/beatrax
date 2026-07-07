<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Reports\Internal\Aggregation\PeriodPresetResolver;
use Modules\Reports\Internal\Aggregation\ReportAggregator;
use Modules\Reports\Internal\Http\DrilldownUrlBuilder;
use Modules\Reports\Models\SavedReport;
use Modules\Reports\Public\Actions\SaveReport;
use Modules\Reports\Public\Dto\ReportDefinition;
use Modules\Reports\Public\Dto\ReportResultDto;
use Modules\Reports\Public\Dto\ReportResultRow;

/**
 * The `/reports` live single-page builder (D-01, Req 1/8/12). Every control
 * is a `#[Url]`-bound property so the current composition is a shareable,
 * refresh-stable URL; changing any control re-runs `ReportAggregator::run()`
 * on the next Livewire round trip — no page reload, no "Apply" button.
 *
 * `#[Url]` alias/`except` pairs mirror `TransactionsList`'s idiom AND the
 * short param names `reports.export` (Plan 07) already committed to, so a
 * builder-driven "Export CSV" link can pass the current URL query string
 * straight through unchanged (999.6-PATTERNS.md "ReportBuilder.php").
 *
 * `mount(?int $report = null)` — T-999.6-22 (IDOR): the lookup is an
 * EXPLICIT `user_id` guard via `withoutGlobalScope(UserScope::class)`, not
 * reliance on the ambient session-bound global scope, mirroring the write
 * actions' (Plan 07) "the injected user is always the source of truth"
 * convention. A foreign or missing id silently falls through to the
 * builder's own (default) empty composition — never another user's data,
 * never a 404 (a 404 would confirm the id's existence to an attacker).
 *
 * Constructor-free; every wire action + `render()` take service
 * collaborators as method parameters (`Component` subclasses can't accept
 * constructor injection under this project's strict-rules ruleset).
 */
final class ReportBuilder extends Component
{
    #[Url(as: 'metric', except: 'spend')]
    public string $metric = 'spend';

    #[Url(as: 'dim', except: 'category')]
    public string $dimension = 'category';

    #[Url(as: 'period', except: 'this_month')]
    public string $periodPreset = 'this_month';

    #[Url(as: 'from', except: '')]
    public string $customFrom = '';

    #[Url(as: 'to', except: '')]
    public string $customTo = '';

    #[Url(as: 'gran', except: 'monthly')]
    public string $granularity = 'monthly';

    #[Url(as: 'ccy', except: 'base')]
    public string $currencyMode = 'base';

    #[Url(as: 'viz', except: 'table')]
    public string $viz = 'table';

    #[Url(as: 'cmp', except: false)]
    public bool $compare = false;

    /** @var list<int> */
    #[Url(as: 'account', except: [])]
    public array $filterAccounts = [];

    /** @var list<int> */
    #[Url(as: 'category', except: [])]
    public array $filterCategories = [];

    /** @var list<int> */
    #[Url(as: 'counterparty', except: [])]
    public array $filterCounterparties = [];

    #[Url(as: 'amount_min', except: '')]
    public string $filterAmountMin = '';

    #[Url(as: 'amount_max', except: '')]
    public string $filterAmountMax = '';

    #[Url(as: 'amount_dir', except: 'both')]
    public string $filterAmountDir = 'both';

    /** The saved report id this builder was opened from, when any (?report=). */
    public ?int $loadedReportId = null;

    public bool $showSaveForm = false;

    public string $saveName = '';

    public string $flashMessage = '';

    public function mount(CurrentUser $currentUser, ?int $report = null): void
    {
        if ($report === null) {
            return;
        }

        /** @var SavedReport|null $saved */
        $saved = SavedReport::query()
            ->withoutGlobalScope(UserScope::class)
            ->where('id', $report)
            ->where('user_id', $currentUser->user()->id)
            ->first();

        if ($saved === null) {
            return;
        }

        $this->applyDefinition(ReportDefinition::from($saved->definition));
        $this->loadedReportId = $saved->id;
    }

    public function openSaveForm(): void
    {
        $this->showSaveForm = true;
        $this->saveName = '';
    }

    public function cancelSaveForm(): void
    {
        $this->showSaveForm = false;
        $this->saveName = '';
    }

    public function save(SaveReport $action, CurrentUser $currentUser): void
    {
        $name = trim($this->saveName);
        if ($name === '') {
            return;
        }

        $action->save($currentUser->user(), $this->currentDefinition(), $name);

        $this->showSaveForm = false;
        $this->saveName = '';
        $this->flashMessage = 'Report saved.';
    }

    public function clearFlash(): void
    {
        $this->flashMessage = '';
    }

    public function render(
        ReportAggregator $aggregator,
        CurrentUser $currentUser,
        ViewFactory $views,
        DatabaseManager $db,
        DrilldownUrlBuilder $drilldownUrlBuilder,
        PeriodPresetResolver $periodPresetResolver,
    ): View {
        $user = $currentUser->user();
        $definition = $this->currentDefinition();

        // The "custom" period preset requires both dates; while the user is
        // mid-selection (Custom range chosen, dates not both filled yet)
        // resolving the period would throw — render the friendly empty
        // state instead of a 500 (Rule 2: robustness the plan's own
        // acceptance criteria implicitly requires — "empty selection shows
        // the friendly empty state, not an error").
        $customIncomplete = $definition->periodPreset === 'custom'
            && ($definition->customFrom === null || $definition->customFrom === '' || $definition->customTo === null || $definition->customTo === '');

        if ($customIncomplete) {
            $result = new ReportResultDto(rows: [], totalMinor: 0, currency: $user->base_currency);
            $displayRows = [];
            $drilldownUrls = [];
        } else {
            $result = $aggregator->run($user, $definition);
            // When compare is on, `comparisonRows` is the richer union of
            // current+previous group keys (deltaMinor populated) sorted by
            // abs(delta) desc — that is the set the table/chart renders,
            // never the plain `rows` (which carries no delta info).
            $displayRows = ($definition->compare && $result->comparisonRows !== null) ? $result->comparisonRows : $result->rows;
            $period = $periodPresetResolver->resolve($definition->periodPreset, $definition->customFrom, $definition->customTo);
            $drilldownUrls = array_map(
                static fn (ReportResultRow $row): string => $drilldownUrlBuilder->build($definition->dimension, $row->groupKey, $period, $definition),
                $displayRows,
            );
        }

        $view = $views->make('reports::livewire.report-builder', [
            'result' => $result,
            'displayRows' => $displayRows,
            'definition' => $definition,
            'drilldownUrls' => $drilldownUrls,
            'showDimension' => $definition->metric !== 'net_worth',
            'showGranularity' => $definition->metric === 'net_worth' || $definition->dimension === 'time_bucket',
            'availableAccounts' => $this->availableAccounts($db, $user->id),
            'availableCategories' => $this->availableCategories($db, $user->id),
            'availableCounterparties' => $this->availableCounterparties($db, $user->id),
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Reports · beatrax']);

        return $view;
    }

    private function applyDefinition(ReportDefinition $definition): void
    {
        $this->metric = $definition->metric;
        $this->dimension = $definition->dimension;
        $this->periodPreset = $definition->periodPreset;
        $this->customFrom = $definition->customFrom ?? '';
        $this->customTo = $definition->customTo ?? '';
        $this->granularity = $definition->granularity;
        $this->currencyMode = $definition->currencyMode;
        $this->viz = $definition->viz;
        $this->compare = $definition->compare;
        $this->filterAccounts = $definition->accounts;
        $this->filterCategories = $definition->categories;
        $this->filterCounterparties = $definition->counterparties;
        $this->filterAmountMin = $definition->amountMin ?? '';
        $this->filterAmountMax = $definition->amountMax ?? '';
        $this->filterAmountDir = $definition->amountDirection;
    }

    private function currentDefinition(): ReportDefinition
    {
        return new ReportDefinition(
            metric: $this->metric,
            dimension: $this->dimension,
            periodPreset: $this->periodPreset,
            granularity: $this->granularity,
            currencyMode: $this->currencyMode,
            viz: $this->viz,
            customFrom: $this->customFrom !== '' ? $this->customFrom : null,
            customTo: $this->customTo !== '' ? $this->customTo : null,
            compare: $this->compare,
            accounts: array_values(array_filter($this->filterAccounts, static fn (int $id): bool => $id > 0)),
            categories: array_values(array_filter($this->filterCategories, static fn (int $id): bool => $id > 0)),
            counterparties: array_values(array_filter($this->filterCounterparties, static fn (int $id): bool => $id > 0)),
            amountMin: $this->filterAmountMin !== '' ? $this->filterAmountMin : null,
            amountMax: $this->filterAmountMax !== '' ? $this->filterAmountMax : null,
            amountDirection: $this->filterAmountDir,
        );
    }

    /**
     * @return list<array{id: int, name: string, currency: string}>
     */
    private function availableAccounts(DatabaseManager $db, int $userId): array
    {
        $rows = $db->connection()
            ->table('accounts')
            ->where('user_id', $userId)
            ->orderBy('name')
            ->get(['id', 'name', 'default_currency'])
            ->all();

        return array_values(array_map(static function (object $row): array {
            return [
                'id' => is_numeric($row->id) ? (int) $row->id : 0,
                'name' => is_string($row->name) ? $row->name : '',
                'currency' => is_string($row->default_currency) ? $row->default_currency : 'EUR',
            ];
        }, $rows));
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function availableCategories(DatabaseManager $db, int $userId): array
    {
        $rows = $db->connection()
            ->table('categories')
            ->where(static function (Builder $query) use ($userId): void {
                $query->whereNull('user_id')->orWhere('user_id', $userId);
            })
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();

        return array_values(array_map(static function (object $row): array {
            return [
                'id' => is_numeric($row->id) ? (int) $row->id : 0,
                'name' => is_string($row->name) ? $row->name : '',
            ];
        }, $rows));
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function availableCounterparties(DatabaseManager $db, int $userId): array
    {
        $rows = $db->connection()
            ->table('counterparties')
            ->where('user_id', $userId)
            ->orderBy('display_name')
            ->get(['id', 'display_name'])
            ->all();

        return array_values(array_map(static function (object $row): array {
            return [
                'id' => is_numeric($row->id) ? (int) $row->id : 0,
                'name' => is_string($row->display_name) ? $row->display_name : '',
            ];
        }, $rows));
    }
}
