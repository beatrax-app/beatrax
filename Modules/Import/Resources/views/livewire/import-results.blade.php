<div class="space-y-6">
    <header class="space-y-1">
        <h1 class="text-2xl font-semibold text-slate-900 tracking-tight">Import complete</h1>
    </header>

    <p class="text-sm text-slate-900">
        Imported {{ $importRun->inserted_count }} transactions · skipped {{ $importRun->duplicate_count }} duplicates@if ($importRun->enriched_count > 0) · {{ $importRun->enriched_count }} enriched@endif@if ($importRun->error_count > 0) · {{ $importRun->error_count }} errors@endif.
    </p>

    @if ($importRun->duplicate_count > 0)
        <details class="text-sm text-slate-500">
            <summary class="cursor-pointer">Show skipped duplicates ({{ $importRun->duplicate_count }})</summary>
            <p class="mt-2 text-slate-500">Duplicates are rows already present in your ledger — they are silently skipped on re-import.</p>
        </details>
    @endif

    @if ($importRun->error_count > 0)
        <details class="text-sm text-slate-500" open>
            <summary class="cursor-pointer">Show errors ({{ $importRun->error_count }})</summary>
            <p class="mt-2 text-slate-500">Errors are rows that could not be parsed; they were not added to your ledger.</p>
        </details>
    @endif

    <div>
        <a
            href="/imports/new"
            class="inline-flex items-center rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-900 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
        >
            Upload another statement
        </a>
    </div>
</div>
