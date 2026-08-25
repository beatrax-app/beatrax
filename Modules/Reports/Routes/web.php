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

// loadRoutesFrom() uses a plain require, so this file can re-execute in one
// process and a top-level named function would fatal on the second boot.
Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/reports/export', static function (
        Request $request,
        ResponseFactory $responses,
        ReportCsvExporter $exporter,
        CurrentUser $currentUser,
        ReportDefinitionRequestFactory $definitions,
    ): StreamedResponse {
        if (! $currentUser->isAuthenticated()) {
            return new StreamedResponse(static function (): void {
                // Empty on purpose, and it may not stay that way by accident.
                // The group's 'auth' makes this unreachable; it exists because
                // user() throws NotAuthenticatedException, which is mapped to
                // no response — letting it out would 500 instead of signing in.
            });
        }

        $user = $currentUser->user();
        $definition = $definitions->fromExportQuery($request);

        return $responses->streamDownload(
            static function () use ($exporter, $user, $definition): void {
                echo $exporter->export($user, $definition);
            },
            "beatrax-report-{$definition->slug()}.csv",
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    })->name('reports.export');

    // ?report={id} is resolved into a view variable here so the Blade view does
    // not reach for the request() global helper.
    Route::get('/reports', static fn (Request $request) => view('reports::report-builder', [
        'report' => $request->integer('report') ?: null,
    ]))->name('reports.index');

    Route::get('/reports/library', ReportsIndex::class)->name('reports.library');
});
