<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\HoldsFlashMessage;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\LocaleCollator;
use Modules\Counterparties\Public\Queries\CounterpartyDisplayName;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Enums\AmountDirection;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\Support\CategoryPathName;
use Modules\Mobile\Public\Services\ShareSheetExport;
use Modules\Reports\Internal\Actions\SaveReport;
use Modules\Reports\Internal\Actions\UpdateReport;
use Modules\Reports\Internal\Aggregation\PeriodPresetResolver;
use Modules\Reports\Internal\Aggregation\ReportAggregator;
use Modules\Reports\Internal\Aggregation\ReportMetric;
use Modules\Reports\Internal\Aggregation\TimeBucketGenerator;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Dto\ReportResultDto;
use Modules\Reports\Internal\Dto\ReportResultRow;
use Modules\Reports\Internal\Enums\ReportCurrencyMode;
use Modules\Reports\Internal\Enums\ReportDimension;
use Modules\Reports\Internal\Enums\ReportGranularity;
use Modules\Reports\Internal\Enums\ReportMetricSelection;
use Modules\Reports\Internal\Enums\ReportPeriodPreset;
use Modules\Reports\Internal\Enums\ReportViz;
use Modules\Reports\Internal\Exceptions\InvalidReportPeriod;
use Modules\Reports\Internal\Http\DrilldownUrlBuilder;
use Modules\Reports\Internal\Services\ReportCsvExporter;
use Modules\Reports\Internal\Support\ChartSeries;
use Modules\Reports\Internal\Support\ReportDefinitionFactory;
use Modules\Reports\Internal\Support\ReportVocabulary;
use Modules\Reports\Models\SavedReport;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportBuilder extends Component
{
    use HoldsFlashMessage;

    #[Url(as: 'metric', except: ReportMetricSelection::Spend->value)]
    public string $metric = ReportMetricSelection::Spend->value;

    #[Url(as: 'dim', except: ReportDimension::Category->value)]
    public string $dimension = ReportDimension::Category->value;

    #[Url(as: 'period', except: ReportPeriodPreset::ThisMonth->value)]
    public string $periodPreset = ReportPeriodPreset::ThisMonth->value;

    #[Url(as: 'from', except: '')]
    public string $customFrom = '';

    #[Url(as: 'to', except: '')]
    public string $customTo = '';

    #[Url(as: 'gran', except: ReportGranularity::Monthly->value)]
    public string $granularity = ReportGranularity::Monthly->value;

    #[Url(as: 'ccy', except: ReportCurrencyMode::Base->value)]
    public string $currencyMode = ReportCurrencyMode::Base->value;

    #[Url(as: 'viz', except: ReportViz::Table->value)]
    public string $viz = ReportViz::Table->value;

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

    #[Url(as: 'amount_dir', except: AmountDirection::Both->value)]
    public string $filterAmountDir = AmountDirection::Both->value;

    // Set only from a stored report the reader just opened or saved; the save
    // path updates whatever id this holds.
    #[Locked]
    public ?int $loadedReportId = null;

    // Stashed so openSaveForm() can pre-fill saveName: a blank field implies
    // "Save report" forks a new row rather than updating the open one.
    public string $loadedReportName = '';

    public bool $showSaveForm = false;

    public string $saveName = '';

    public function mount(CurrentUser $currentUser, ?int $report = null): void
    {
        if ($report === null) {
            return;
        }

        // An explicit user_id check, since a 404 would confirm existence to an
        // attacker; a foreign or missing id falls through to the empty default.
        /** @var SavedReport|null $saved */
        $saved = SavedReport::query()
            ->withoutGlobalScope(UserScope::class)
            ->where('id', $report)
            ->where('user_id', $currentUser->user()->id)
            ->first();

        if ($saved === null) {
            return;
        }

        $this->applyDefinition(ReportDefinitionFactory::fromStored($saved->definition));
        $this->loadedReportId = $saved->id;
        $this->loadedReportName = $saved->name;
    }

    public function openSaveForm(): void
    {
        $this->showSaveForm = true;
        $this->saveName = $this->loadedReportId !== null ? $this->loadedReportName : '';
    }

    public function cancelSaveForm(): void
    {
        $this->showSaveForm = false;
        $this->saveName = '';
    }

    // A builder opened from a saved report updates that row; a fresh save stashes
    // its id, so a second save on the same page load also updates in place.
    public function save(SaveReport $action, UpdateReport $updateAction, CurrentUser $currentUser): void
    {
        $name = trim($this->saveName);
        if ($name === '') {
            return;
        }

        if ($this->loadedReportId !== null) {
            $updateAction->update($currentUser->user(), $this->loadedReportId, $this->currentDefinition(), $name);
            $this->loadedReportName = $name;
            $this->flashMessage = Lang::get('reports::builder.flash.updated');
        } else {
            $saved = $action->save($currentUser->user(), $this->currentDefinition(), $name);
            $this->loadedReportId = $saved->id;
            $this->loadedReportName = $saved->name;
            $this->flashMessage = Lang::get('reports::builder.flash.saved');
        }

        $this->showSaveForm = false;
        $this->saveName = '';
    }

    public function clearFlash(): void
    {
        $this->flashMessage = '';
    }

    // One generic hook rather than a dispatch per action, since every control
    // here is a bare setter; ApexCharts refreshes in place off the event.
    public function updated(string $property): void
    {
        $this->dispatch('report-updated');
    }

    // A Livewire action rather than an <a href> so it joins wire:loading, and it
    // reads the same currentDefinition() the table renders from.
    public function export(ResponseFactory $responses, ReportCsvExporter $exporter, CurrentUser $currentUser, PeriodPresetResolver $periodPresetResolver, ShareSheetExport $shareSheet): ?StreamedResponse
    {
        if (! $currentUser->isAuthenticated()) {
            return new StreamedResponse(static function (): void {
                // Empty on purpose, and it may not stay that way by accident.
                // The route's 'auth' makes this unreachable; it exists because
                // user() throws NotAuthenticatedException, which is mapped to
                // no response — letting it out would 500 instead of signing in.
            });
        }

        $user = $currentUser->user();

        $definition = $this->currentDefinition();

        // Checked before the stream opens, never inside it: an exception thrown
        // from the download callback has already sent 200 and the headers, so
        // the reader gets a truncated file instead of the message.
        try {
            $periodPresetResolver->resolve($definition->periodPreset, $definition->customFrom, $definition->customTo);
        } catch (InvalidReportPeriod $problem) {
            $this->flashMessage = self::periodMessage($problem);

            return null;
        }

        $filename = "beatrax-report-{$definition->slug()}.csv";

        // A shell whose WebView drops the download gets the OS share sheet and
        // a line saying so. The response it would have been sent goes nowhere,
        // which is exactly what the reader saw on the phone: nothing.
        $handedToTheShareSheet = $shareSheet->replacesWebViewDownload();

        if ($handedToTheShareSheet) {
            $this->flashMessage = $shareSheet->export($filename, $exporter->export($user, $definition))->message();
        }

        return $handedToTheShareSheet ? null : $responses->streamDownload(
            static function () use ($exporter, $user, $definition): void {
                echo $exporter->export($user, $definition);
            },
            $filename,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    public function render(
        ReportAggregator $aggregator,
        CurrentUser $currentUser,
        ViewFactory $views,
        DatabaseManager $db,
        DrilldownUrlBuilder $drilldownUrlBuilder,
        PeriodPresetResolver $periodPresetResolver,
        TimeBucketGenerator $timeBucketGenerator,
        BaseCurrency $baseCurrency,
        CounterpartyDisplayName $counterpartyNames,
    ): View {
        $user = $currentUser->user();
        // The property itself, not only the way to the definition: Livewire
        // hands the raw array to the view too, and the filter chips subscript
        // [0] on a count of one, which a keyed ?account[key]= satisfies without
        // ever having a zero.
        $this->filterAccounts = ReportVocabulary::ids($this->filterAccounts);
        $this->filterCategories = ReportVocabulary::ids($this->filterCategories);
        $this->filterCounterparties = ReportVocabulary::ids($this->filterCounterparties);

        $definition = $this->currentDefinition();
        $isNetWorth = $definition->metric === ReportMetricSelection::NetWorth->value;

        // Picking the end date before the start one is an ordinary mid-edit
        // state in a two-date picker. The reader gets told which half is wrong
        // and keeps the composition; resolving it threw an HTML error page.
        $periodError = '';
        $result = new ReportResultDto(rows: [], totalMinor: 0, currency: $baseCurrency->forUser($user));
        $displayRows = [];
        $drilldownUrls = [];

        try {
            $period = $periodPresetResolver->resolve($definition->periodPreset, $definition->customFrom, $definition->customTo);
            $result = $aggregator->run($user, $definition);
            // With compare on, comparisonRows is the union of current+previous
            // keys carrying deltaMinor; plain `rows` has no delta info.
            $displayRows = ($definition->compare && $result->comparisonRows !== null) ? $result->comparisonRows : $result->rows;
            $drilldownUrls = self::drilldownUrls($drilldownUrlBuilder, $definition, $displayRows, $period, $timeBucketGenerator);
        } catch (InvalidReportPeriod $problem) {
            $periodError = self::periodMessage($problem);
        }

        $view = $views->make('reports::livewire.report-builder', [
            'result' => $result,
            'displayRows' => $displayRows,
            // A chart axis carries one currency and a ring one direction, so
            // what it can draw is narrower than what the table lists -- and
            // every row it drops is said on the page, never dropped quietly.
            'chartSeries' => ChartSeries::for($definition->viz, $displayRows, $drilldownUrls, $result->currency, $result->totalMinor),
            'definition' => $definition,
            'drilldownUrls' => $drilldownUrls,
            'periodError' => $periodError,
            'showDimension' => ! $isNetWorth,
            'showGranularity' => $isNetWorth || $definition->dimension === ReportDimension::TimeBucket->value,
            'showTransactionFilters' => ! $isNetWorth,
            'ignoredFilterNote' => $isNetWorth && self::carriesTransactionFilters($definition)
                ? Lang::get('reports::builder.filters.net_worth_note')
                : '',
            'otherMovementLabel' => Lang::get($isNetWorth || ! ReportMetric::fromMetric($definition->metric)->disclosesRefunds()
                ? 'reports::builder.other_movement'
                : 'reports::builder.other_movement_with_refunds'),
            'availableAccounts' => $this->availableAccounts($db, $user->id, $baseCurrency->code()),
            'availableCategories' => $this->availableCategories($db, $user->id),
            'availableCounterparties' => $this->availableCounterparties($counterpartyNames, $user->id),
        ]);

        $view->extends('layouts.app', ['title' => Lang::get('reports::builder.page_title')]);

        return $view;
    }

    // A time bucket and a net-worth point each own a WINDOW, and the row's
    // group key is that window's date. Handed the report's whole period every
    // monthly row linked to one identical full-range list.
    /**
     * @param  list<ReportResultRow>  $displayRows
     * @return list<string>
     */
    private static function drilldownUrls(DrilldownUrlBuilder $builder, ReportDefinition $definition, array $displayRows, Period $period, TimeBucketGenerator $timeBucketGenerator): array
    {
        $buckets = self::bucketsByDate($definition, $period, $timeBucketGenerator);

        return array_map(
            static fn (ReportResultRow $row): string => $builder->build(
                $definition->dimension,
                $row->groupKey,
                (is_string($row->groupKey) ? $buckets[$row->groupKey] ?? null : null) ?? $period,
                $definition,
            ),
            $displayRows,
        );
    }

    // Keyed by both ends: a time-bucket row is keyed by its window's start date
    // and a net-worth point by its sample date, which is the last day of the
    // same window.
    /**
     * @return array<string, Period>
     */
    private static function bucketsByDate(ReportDefinition $definition, Period $period, TimeBucketGenerator $timeBucketGenerator): array
    {
        if ($definition->metric !== ReportMetricSelection::NetWorth->value && $definition->dimension !== ReportDimension::TimeBucket->value) {
            return [];
        }

        $byDate = [];
        foreach ($timeBucketGenerator->generate($period, $definition->granularity) as $bucket) {
            $byDate[$bucket->start->toDateString()] = $bucket;
            $byDate[$bucket->endExclusive->subDay()->toDateString()] = $bucket;
        }

        return $byDate;
    }

    private static function periodMessage(InvalidReportPeriod $problem): string
    {
        return Lang::get('reports::builder.period.error.'.$problem->problem->value);
    }

    private static function carriesTransactionFilters(ReportDefinition $definition): bool
    {
        return $definition->categories !== []
            || $definition->counterparties !== []
            || $definition->amountMin !== null
            || $definition->amountMax !== null
            || $definition->amountDirection !== AmountDirection::Both->value;
    }

    private function applyDefinition(ReportDefinition $definition): void
    {
        $this->metric = $definition->metric;
        $this->dimension = $definition->dimension;
        $this->periodPreset = $definition->periodPreset;
        $this->customFrom = $definition->customFrom ?? '';
        $this->customTo = $definition->customTo ?? '';
        $this->granularity = $definition->granularity->value;
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
        // Every #[Url] property is reader-supplied, so each one is coerced to a
        // value the aggregator names rather than passed through: an unknown
        // ?metric=, ?period= or ?ccy= reached a match() with no arm and 500'd
        // the page. The rail can only produce valid values; the address bar cannot.
        return new ReportDefinition(
            metric: ReportVocabulary::metric($this->metric),
            dimension: ReportVocabulary::dimension($this->dimension),
            periodPreset: ReportVocabulary::periodPreset($this->periodPreset),
            granularity: ReportVocabulary::granularity($this->granularity),
            currencyMode: ReportVocabulary::currencyMode($this->currencyMode),
            viz: ReportVocabulary::viz($this->viz),
            customFrom: $this->customFrom !== '' ? $this->customFrom : null,
            customTo: $this->customTo !== '' ? $this->customTo : null,
            compare: $this->compare,
            accounts: ReportVocabulary::ids($this->filterAccounts),
            categories: ReportVocabulary::ids($this->filterCategories),
            counterparties: ReportVocabulary::ids($this->filterCounterparties),
            amountMin: $this->filterAmountMin !== '' ? $this->filterAmountMin : null,
            amountMax: $this->filterAmountMax !== '' ? $this->filterAmountMax : null,
            amountDirection: $this->filterAmountDir,
        );
    }

    /**
     * @return list<array{id: int, name: string, currency: string}>
     */
    private function availableAccounts(DatabaseManager $db, int $userId, string $baseCurrencyCode): array
    {
        $rows = $db->connection()
            ->table('accounts')
            ->where('user_id', $userId)
            ->orderBy('name')
            ->get(['id', 'name', 'default_currency'])
            ->all();

        return array_values(array_map(static function (object $row) use ($baseCurrencyCode): array {
            return [
                'id' => is_numeric($row->id) ? (int) $row->id : 0,
                'name' => is_string($row->name) ? $row->name : '',
                'currency' => is_string($row->default_currency) ? $row->default_currency : $baseCurrencyCode,
            ];
        }, $rows));
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function availableCategories(DatabaseManager $db, int $userId): array
    {
        // The result rows beside this filter are fully qualified, so a filter
        // offering the bare leaf named the same category two different ways on
        // one screen.
        $rows = CategoryPathName::joinParent($db->connection()->table('categories as c'), $userId, 'c', 'cp')
            ->where(static function (Builder $query) use ($userId): void {
                $query->whereNull('c.user_id')->orWhere('c.user_id', $userId);
            })
            ->get(['c.id', ...CategoryPathName::columns('c', 'cp')])
            ->all();

        $paths = [];
        foreach ($rows as $row) {
            $paths[is_numeric($row->id) ? (int) $row->id : 0] = CategoryPathName::fromRow($row) ?? '';
        }

        $options = [];
        foreach (CategoryPathName::distinct($paths) as $id => $name) {
            $options[] = ['id' => $id, 'name' => $name];
        }

        usort($options, static function (array $a, array $b): int {
            $byName = LocaleCollator::compare($a['name'], $b['name']);

            return $byName !== 0 ? $byName : $a['id'] <=> $b['id'];
        });

        return $options;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function availableCounterparties(CounterpartyDisplayName $counterpartyNames, int $userId): array
    {
        return array_values($counterpartyNames->forUser($userId)
            ->map(static fn (stdClass $row): array => [
                'id' => $row->id,
                'name' => $row->display_name,
            ])
            ->all());
    }
}
