<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Reports\Internal\Aggregation\PeriodPresetResolver;
use Modules\Reports\Internal\Aggregation\ReportAggregator;
use Modules\Reports\Internal\Http\DrilldownUrlBuilder;
use Modules\Reports\Internal\Services\ReportCsvExporter;
use Modules\Reports\Models\SavedReport;
use Modules\Reports\Public\Actions\SaveReport;
use Modules\Reports\Public\Actions\UpdateReport;
use Modules\Reports\Public\Dto\ReportDefinition;
use Modules\Reports\Public\Dto\ReportResultDto;
use Modules\Reports\Public\Dto\ReportResultRow;
use Modules\Reports\Public\Enums\ReportGranularity;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @link ../../../../../.docs/features/reports/architecture.md
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

    // The saved report id this builder was opened from, when any
    // (?report= on mount).
    public ?int $loadedReportId = null;

    // Stashed in mount() so openSaveForm() can pre-fill saveName with it
    // instead of showing a blank field that implies "Save report" will
    // create a new row rather than update the one currently open.
    public string $loadedReportName = '';

    public bool $showSaveForm = false;

    public string $saveName = '';

    public string $flashMessage = '';

    public function mount(CurrentUser $currentUser, ?int $report = null): void
    {
        if ($report === null) {
            return;
        }

        // IDOR guard: explicit user_id check via withoutGlobalScope. A
        // foreign or missing id falls through to the default empty
        // composition — never another user's data, never a 404 (which
        // would confirm existence to an attacker).
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
        // Pre-fill with the currently-loaded report's name (rather than
        // resetting to '') so the user sees at a glance that submitting
        // will update that report, not fork a second identically-named row.
        $this->saveName = $this->loadedReportId !== null ? $this->loadedReportName : '';
    }

    public function cancelSaveForm(): void
    {
        $this->showSaveForm = false;
        $this->saveName = '';
    }

    // A builder opened from a saved report (loadedReportId !== null)
    // updates that same row rather than forking a new one; a fresh save's
    // id is stashed into loadedReportId so a subsequent save on the same
    // page load also updates in place instead of duplicating.
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

    // Dispatches a browser event after every Livewire property sync so the
    // mounted ApexCharts instance can refresh in place via
    // chart.updateOptions() — a single generic hook rather than one
    // dispatch per action, since every control here is a bare setter.
    public function updated(string $property): void
    {
        $this->dispatch('report-updated');
    }

    // A real Livewire action (not a plain <a href>) so it can participate
    // in wire:loading/wire:target; reads through the same
    // currentDefinition() the table/chart render from, so the download
    // can never disagree with what's on screen.
    public function export(ResponseFactory $responses, ReportCsvExporter $exporter, CurrentUser $currentUser): StreamedResponse
    {
        if (! $currentUser->isAuthenticated()) {
            // Defensive branch: the 'auth' middleware already blocks
            // unauthenticated access before this method ever runs, so the
            // stream body is intentionally empty here.
            return new StreamedResponse(static function (): void {
                // No authenticated user means no report to stream; an empty
                // body satisfies the StreamedResponse contract without
                // exposing another user's data.
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
        SensitiveColumnCodec $codec,
        Session $session,
        BaseCurrency $baseCurrency,
    ): View {
        $user = $currentUser->user();
        $definition = $this->currentDefinition();

        // The "custom" preset requires both dates; while the user is
        // mid-selection (dates not both filled yet) resolving the period
        // would throw, so render the friendly empty state instead of a 500.
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
            'availableAccounts' => $this->availableAccounts($db, $user->id, $baseCurrency->code()),
            'availableCategories' => $this->availableCategories($db, $user->id),
            'availableCounterparties' => $this->availableCounterparties($db, $user->id, $codec, $session),
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
        return new ReportDefinition(
            metric: $this->metric,
            dimension: $this->dimension,
            periodPreset: $this->periodPreset,
            granularity: ReportGranularity::tryFrom($this->granularity) ?? ReportGranularity::default(),
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
    private function availableCounterparties(DatabaseManager $db, int $userId, SensitiveColumnCodec $codec, Session $session): array
    {
        // No ORDER BY on the ciphertext display_name column — SQL order
        // over ciphertext is meaningless once encryption is enabled. A
        // stable orderBy('id') keeps row iteration deterministic; the
        // real, user-facing order is the post-decrypt usort() below.
        $rows = $db->connection()
            ->table('counterparties')
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get(['id', 'display_name'])
            ->all();

        $result = array_values(array_map(static function (object $row) use ($codec, $session, $userId): array {
            $stored = is_string($row->display_name ?? null) ? $row->display_name : '';
            $name = $stored === ''
                ? ''
                : $codec->decryptValue('counterparties', 'display_name', $stored, $userId, $session)['value'];

            return [
                'id' => is_numeric($row->id) ? (int) $row->id : 0,
                'name' => $name,
            ];
        }, $rows));

        usort($result, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $result;
    }
}
