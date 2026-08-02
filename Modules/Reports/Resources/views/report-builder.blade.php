{{--
    Route-target wrapper view for `reports.index` (/reports) — mirrors
    `categorization::rules`'s `@extends('layouts.app', ...)` +
    `@livewire(...)` shape (this codebase's established full-page-component
    convention) rather than routing directly to the Livewire component
    class.

    $report — nullable int, the optional ?report={id} saved-report id to
    preload (Modules/Reports/Routes/web.php resolves it from the query
    string before this view renders).
--}}
@use('Modules\Core\Public\Support\Lang')
@extends('layouts.app', ['title' => Lang::get('reports::builder.page_title')])

@section('content')
    @livewire(\Modules\Reports\Internal\Http\Livewire\ReportBuilder::class, ['report' => $report])
@endsection
