<?php

declare(strict_types=1);

use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Reports\Internal\Http\Livewire\ReportsIndex;
use Modules\Reports\Internal\Services\ReportCsvExporter;
use Modules\Reports\Public\Dto\ReportDefinition;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
 * 999.6-08: the `/reports` live single-page builder (D-01). Routes to a
 * thin wrapper Blade view (`reports::report-builder`, mirrors
 * `categorization::rules`'s `@extends('layouts.app', ...)` +
 * `@livewire(...)` shape) rather than directly to the Livewire component
 * class — this codebase's established full-page-component convention
 * (Route::view + @livewire inside @section('content')), not the
 * alternative "route straight to the component class" Livewire pattern.
 */

/*
 * Reports module web routes.
 *
 * Owned by 999.6-07 (this file is created here, not by Plan 01) — later
 * plans in this phase APPEND their routes (builder, index) below this
 * group; `ReportsServiceProvider::boot()` already `loadRoutesFrom` this
 * path unconditionally.
 *
 * `reports.export` (Req 11) streams the current report definition as a CSV
 * download. The definition is built from validated query params using the
 * SAME short names the ReportBuilder's `#[Url]`-bound control-rail
 * properties use (metric/dim/period/gran/ccy/viz/cmp/account/category/
 * counterparty/amount_min/amount_max/amount_dir/from/to —
 * 999.6-PATTERNS.md "ReportBuilder.php" `#[Url]` list) so a builder-driven
 * "Export CSV" link can pass its current URL query string straight through
 * unchanged.
 *
 * Mirrors `Modules\Tax\Internal\Http\Livewire\TaxPage::exportCsv()`'s
 * unauthenticated -> empty StreamedResponse guard (T-999.6-20) even though
 * the route already sits behind the `auth` middleware group below —
 * defense-in-depth, matching the Tax precedent exactly.
 *
 * NOTE: `ServiceProvider::loadRoutesFrom()` uses a plain `require` (not
 * `require_once`), so this file can be re-executed within the same PHP
 * process across multiple app boots (e.g. repeated test-suite boots). A
 * top-level named `function` declared here would fatal with "Cannot
 * redeclare" on the second boot — every helper below is therefore kept as
 * an anonymous closure local to the route handler, never a named top-level
 * function (this is why every other module's Routes/web.php avoids them
 * too).
 */
Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/reports/export', static function (
        Request $request,
        ResponseFactory $responses,
        ReportCsvExporter $exporter,
        CurrentUser $currentUser,
    ): StreamedResponse {
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
            granularity: is_string($request->query('gran')) ? $request->query('gran') : 'monthly',
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

    // 999.6-08: the live single-page builder. Optional ?report={id} query
    // param loads a user-owned saved definition (ReportBuilder::mount()).
    // Mirrors Modules/Import/Routes/web.php's preview route shape — a
    // closure resolving the query/route param into a plain view variable,
    // rather than reading the request() global helper inside the Blade
    // view itself.
    Route::get('/reports', static fn (Request $request) => view('reports::report-builder', [
        'report' => $request->integer('report') ?: null,
    ]))->name('reports.index');

    // 999.6-09: the saved-report index (Req 9). Routes directly to the
    // Livewire component class (mirrors CalendarPage/BudgetsPage — the
    // component's own render() calls $view->extends('layouts.app', ...),
    // so a wrapper Blade view is unnecessary here).
    Route::get('/reports/library', ReportsIndex::class)->name('reports.library');
});
