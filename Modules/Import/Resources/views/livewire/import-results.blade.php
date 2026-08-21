@use('Modules\Core\Public\Support\Lang')
{{-- D-06 / UI-SPEC §19: overflow-x:auto on outer wrapper so this surface
     scrolls horizontally at phone width rather than forcing page overflow. --}}
<div class="space-y-6 overflow-x-auto">
    <header class="space-y-1">
        <h1 class="text-2xl font-semibold text-slate-900 tracking-tight dark:text-slate-100">{{ Lang::get('import::results.heading') }}</h1>
    </header>

    <p class="text-sm text-slate-900 dark:text-slate-100">
        {{ Lang::get('import::results.summary', ['inserted' => $importRun->inserted_count, 'duplicates' => $importRun->duplicate_count]) }}{{ $importRun->enriched_count > 0 ? Lang::get('import::results.summary_enriched', ['count' => $importRun->enriched_count]) : '' }}{{ $importRun->error_count > 0 ? Lang::get('import::results.summary_errors', ['count' => $importRun->error_count]) : '' }}.
    </p>

    @if ($importRun->duplicate_count > 0)
        <details class="text-sm text-slate-500 dark:text-slate-400">
            <summary class="cursor-pointer">{{ Lang::get('import::results.show_duplicates', ['count' => $importRun->duplicate_count]) }}</summary>
            <p class="mt-2 text-slate-500 dark:text-slate-400">{{ Lang::get('import::results.duplicates_help') }}</p>
        </details>
    @endif

    @if ($importRun->error_count > 0)
        <details class="text-sm text-slate-500 dark:text-slate-400" open>
            <summary class="cursor-pointer">{{ Lang::get('import::results.show_errors', ['count' => $importRun->error_count]) }}</summary>
            <p class="mt-2 text-slate-500 dark:text-slate-400">{{ Lang::get('import::results.errors_help') }}</p>
        </details>
    @endif

    <div>
        <x-core::secondary-button href="/imports/new">
            {{ Lang::get('import::results.upload_another') }}
        </x-core::secondary-button>
    </div>
</div>
