<?php

declare(strict_types=1);

use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Reports\Internal\Http\Livewire\ReportsIndex;
use Modules\Reports\Internal\Services\ReportCsvExporter;
use Modules\Reports\Public\Dto\ReportDefinition;
use Modules\Reports\Public\Enums\ReportGranularity;
use Symfony\Component\HttpFoundation\StreamedResponse;

// ServiceProvider::loadRoutesFrom() uses a plain require (not require_once),
// so this file can re-execute within the same PHP process across multiple
// app boots. A top-level named function here would fatal with "Cannot
// redeclare" on the second boot, so every helper below is an anonymous closure.
Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/reports/export', static function (
        Request $request,
        ResponseFactory $responses,
        ReportCsvExporter $exporter,
        CurrentUser $currentUser,
    ): StreamedResponse {
        // Defense-in-depth: the route already sits behind the 'auth'
        // middleware group above, so this branch is unreachable in practice.
        if (! $currentUser->isAuthenticated()) {
            return new StreamedResponse(static function (): void {});
        }

        $user = $currentUser->user();

        $toIntList = static function (mixed $value): array {
            if (! is_array($value)) {
                return [];
            }

            return array_values(array_map(
                static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
                array_filter($value, static fn (mixed $id): bool => is_numeric($id)),
            ));
        };

        $toNullableString = static function (mixed $value): ?string {
            return is_string($value) && $value !== '' ? $value : null;
        };

        $definition = new ReportDefinition(
            metric: is_string($request->query('metric')) ? $request->query('metric') : 'spend',
            dimension: is_string($request->query('dim')) ? $request->query('dim') : 'category',
            periodPreset: is_string($request->query('period')) ? $request->query('period') : 'this_month',
            // tryFrom, not from: an unknown ?gran= is a bad link rather than
            // corrupt state, and it used to reach TimeBucketGenerator and
            // throw, so ?gran=nonsense was a 500. The stored-value rejection
            // C7-R21 asks for happens in ReportDefinition::from() instead.
            granularity: ReportGranularity::tryFrom(
                is_string($request->query('gran')) ? $request->query('gran') : '',
            ) ?? ReportGranularity::default(),
            currencyMode: is_string($request->query('ccy')) ? $request->query('ccy') : 'base',
            viz: is_string($request->query('viz')) ? $request->query('viz') : 'table',
            customFrom: $toNullableString($request->query('from')),
            customTo: $toNullableString($request->query('to')),
            compare: $request->boolean('cmp'),
            accounts: $toIntList($request->query('account', [])),
            categories: $toIntList($request->query('category', [])),
            counterparties: $toIntList($request->query('counterparty', [])),
            amountMin: $toNullableString($request->query('amount_min')),
            amountMax: $toNullableString($request->query('amount_max')),
            amountDirection: is_string($request->query('amount_dir')) ? $request->query('amount_dir') : 'both',
        );

        return $responses->streamDownload(
            static function () use ($exporter, $user, $definition): void {
                echo $exporter->export($user, $definition);
            },
            "beatrax-report-{$definition->slug()}.csv",
        );
    })->name('reports.export');

    // The live single-page builder. Optional ?report={id} query param
    // loads a user-owned saved definition (ReportBuilder::mount()); the
    // query param is resolved here into a plain view variable rather than
    // read via the request() global helper inside the Blade view itself.
    Route::get('/reports', static fn (Request $request) => view('reports::report-builder', [
        'report' => $request->integer('report') ?: null,
    ]))->name('reports.index');

    // Routes directly to the Livewire component class since its own
    // render() calls $view->extends('layouts.app', ...), so a wrapper
    // Blade view is unnecessary here.
    Route::get('/reports/library', ReportsIndex::class)->name('reports.library');
});
