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
use Modules\Core\Public\Http\Livewire\Concerns\HoldsFlashMessage;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Core\Public\Support\Lang;
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
use Modules\Reports\Models\SavedReport;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
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
            // The 'auth' middleware already blocks this, so the body is empty.
            return new StreamedResponse(static function (): void {
                // No user, no report to stream.
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
            ->get(['id', 'name', 'slug', 'name_is_default'])
            ->all();

        $options = array_values(array_map(static function (object $row): array {
            return [
                'id' => is_numeric($row->id) ? (int) $row->id : 0,
                'name' => CategoryDisplayName::fromRow($row) ?? '',
            ];
        }, $rows));

        usort($options, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $options;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function availableCounterparties(DatabaseManager $db, int $userId, SensitiveColumnCodec $codec, Session $session): array
    {
        // SQL order over the ciphertext display_name is meaningless once
        // encryption is on, so orderBy('id') only keeps iteration deterministic;
        // the user-facing order is the post-decrypt usort() below.
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
