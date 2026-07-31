<?php

declare(strict_types=1);

use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Reports\Internal\Http\Livewire\ReportsIndex;
use Modules\Reports\Internal\Http\ReportDefinitionRequestFactory;
use Modules\Reports\Internal\Services\ReportCsvExporter;
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
        ReportDefinitionRequestFactory $definitions,
    ): StreamedResponse {
        // Defense-in-depth: the route already sits behind the 'auth'
        // middleware group above, so this branch is unreachable in practice.
        if (! $currentUser->isAuthenticated()) {
            return new StreamedResponse(static function (): void {
                // Nothing to stream: the guard above only fires in the
                // unreachable unauthenticated case, so an empty body keeps
                // the StreamedResponse return type without leaking a report.
            });
        }

        $user = $currentUser->user();
        $definition = $definitions->fromExportQuery($request);

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
