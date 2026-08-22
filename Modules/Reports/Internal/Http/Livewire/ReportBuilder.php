<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\HoldsFlashMessage;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\LocaleCollator;
use Modules\Counterparties\Public\Queries\CounterpartyDisplayName;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\Support\CategoryDisplayName;
use Modules\Reports\Internal\Actions\SaveReport;
use Modules\Reports\Internal\Actions\UpdateReport;
use Modules\Reports\Internal\Aggregation\PeriodPresetResolver;
use Modules\Reports\Internal\Aggregation\ReportAggregator;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Dto\ReportResultDto;
use Modules\Reports\Internal\Dto\ReportResultRow;
use Modules\Reports\Internal\Enums\ReportGranularity;
use Modules\Reports\Internal\Http\DrilldownUrlBuilder;
use Modules\Reports\Internal\Services\ReportCsvExporter;
use Modules\Reports\Internal\Support\ReportVocabulary;
use Modules\Reports\Models\SavedReport;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportBuilder extends Component
{
    use HoldsFlashMessage;

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

    #[Url(as: 'gran', except: ReportGranularity::Monthly->value)]
    public string $granularity = ReportGranularity::Monthly->value;

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

        $this->applyDefinition(ReportDefinition::from($saved->definition));
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
    public function export(ResponseFactory $responses, ReportCsvExporter $exporter, CurrentUser $currentUser): StreamedResponse
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

        return $responses->streamDownload(
            static function () use ($exporter, $user, $definition): void {
                echo $exporter->export($user, $definition);
            },
            "beatrax-report-{$definition->slug()}.csv",
        );
    }

    public function render(
        ReportAggregator $aggregator,
        CurrentUser $currentUser,
        ViewFactory $views,
        DatabaseManager $db,
        DrilldownUrlBuilder $drilldownUrlBuilder,
        PeriodPresetResolver $periodPresetResolver,
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

        // "custom" needs both dates, and resolving mid-selection would throw,
        // so render the empty state rather than a 500.
        $customIncomplete = $definition->periodPreset === 'custom'
            && ($definition->customFrom === null || $definition->customFrom === '' || $definition->customTo === null || $definition->customTo === '');

        if ($customIncomplete) {
            $result = new ReportResultDto(rows: [], totalMinor: 0, currency: $user->base_currency);
            $displayRows = [];
            $drilldownUrls = [];
        } else {
            $result = $aggregator->run($user, $definition);
            // With compare on, comparisonRows is the union of current+previous
            // keys carrying deltaMinor; plain `rows` has no delta info.
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
            'availableAccounts' => $this->availableAccounts($db, $user->id, $baseCurrency->code()),
            'availableCategories' => $this->availableCategories($db, $user->id),
            'availableCounterparties' => $this->availableCounterparties($counterpartyNames, $user->id),
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('reports::builder.page_title')]);

        return $view;
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
        $rows = $db->connection()
            ->table('categories')
            ->where(static function (Builder $query) use ($userId): void {
                $query->whereNull('user_id')->orWhere('user_id', $userId);
            })
            ->get(['id', ...CategoryDisplayName::bareColumns()])
            ->all();

        $options = array_values(array_map(static function (stdClass $row): array {
            return [
                'id' => is_numeric($row->id) ? (int) $row->id : 0,
                'name' => CategoryDisplayName::fromRow($row) ?? '',
            ];
        }, $rows));

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
