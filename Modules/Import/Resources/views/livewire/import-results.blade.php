@use('Modules\Core\Public\Support\Lang')
{{-- UI-SPEC §19: overflow-x:auto on outer wrapper so this surface
     scrolls horizontally at phone width rather than forcing page overflow. --}}
<div class="space-y-6 overflow-x-auto">
    <header class="space-y-1">
        <h1 class="text-2xl font-semibold text-slate-900 tracking-tight dark:text-slate-100">{{ Lang::get('import::results.heading') }}</h1>
    </header>

    <p class="text-sm text-slate-900 dark:text-slate-100">
        {{ Lang::choice('import::results.summary', $importRun->inserted_count) }}{{ Lang::choice('import::results.summary_duplicates', $importRun->duplicate_count) }}{{ $importRun->enriched_count > 0 ? Lang::get('import::results.summary_enriched', ['count' => $importRun->enriched_count]) : '' }}{{ $importRun->error_count > 0 ? Lang::choice('import::results.summary_errors', $importRun->error_count) : '' }}.
    </p>

    @if ($importRun->duplicate_count > 0)
        <details class="text-sm text-slate-500 dark:text-slate-400">
            <summary class="cursor-pointer">{{ Lang::get('import::results.show_duplicates', ['count' => $importRun->duplicate_count]) }}</summary>
            <p class="mt-2 text-slate-500 dark:text-slate-400">{{ Lang::get('import::results.duplicates_help') }}</p>
            @if (count($duplicateIssues) > 0)
                <ul class="mt-2 space-y-1 text-slate-700 dark:text-slate-300">
                    @foreach ($duplicateIssues as $issue)
                        <li>{{ Lang::get('import::results.issues.duplicate', ['row' => $issue->rowIndex === null ? '?' : $issue->rowIndex + 1]) }}</li>
                    @endforeach
                    @if ($importRun->duplicate_count > count($duplicateIssues))
                        <li>{{ Lang::get('import::results.issues.more', ['count' => $importRun->duplicate_count - count($duplicateIssues)]) }}</li>
                    @endif
                </ul>
            @endif
        </details>
    @endif

    @if ($importRun->error_count > 0)
        <details class="text-sm text-slate-500 dark:text-slate-400" open>
            <summary class="cursor-pointer">{{ Lang::get('import::results.show_errors', ['count' => $importRun->error_count]) }}</summary>
            <p class="mt-2 text-slate-500 dark:text-slate-400">{{ Lang::get('import::results.errors_help') }}</p>
            {{-- The help sentence is the preamble; these are the content. A count
                 in the summary promises a list, and what opened was a definition
                 of the word "error" — nothing a reader could use to fix the file. --}}
            @if (count($errorIssues) > 0)
                <ul class="mt-2 space-y-1 text-slate-700 dark:text-slate-300">
                    @foreach ($errorIssues as $issue)
                        <li>
                            @if ($issue->rowIndex === null)
                                {{ Lang::get('import::results.issues.file', ['reason' => $issue->describe() ?? Lang::get('import::results.issues.unknown_reason')]) }}
                            @else
                                {{ Lang::get('import::results.issues.row', ['row' => $issue->rowIndex + 1, 'reason' => $issue->describe() ?? Lang::get('import::results.issues.unknown_reason')]) }}
                            @endif
                        </li>
                    @endforeach
                    @if ($importRun->error_count > count($errorIssues))
                        <li>{{ Lang::get('import::results.issues.more', ['count' => $importRun->error_count - count($errorIssues)]) }}</li>
                    @endif
                </ul>
            @endif
        </details>
    @endif

    <div>
        <x-core::secondary-button href="/imports/new">
            {{ Lang::get('import::results.upload_another') }}
        </x-core::secondary-button>
    </div>
</div>
