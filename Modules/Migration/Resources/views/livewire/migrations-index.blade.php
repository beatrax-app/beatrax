@use('Modules\Core\Public\Support\Lang')
<div class="space-y-6">
    @if ($runs->isEmpty())
        <div class="flex flex-col items-center gap-4 rounded-md border border-slate-200 bg-slate-50 px-6 py-16 text-center dark:bg-slate-900 dark:border-slate-700">
            <h1 class="text-2xl font-semibold text-slate-900 tracking-tight dark:text-slate-100">{{ Lang::get('migration::index.empty_heading') }}</h1>
            <p class="max-w-md text-sm text-slate-500 dark:text-slate-400">
                {{ Lang::get('migration::index.intro') }}
            </p>
            <a
                href="{{ route('migrations.new') }}"
                class="inline-flex items-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:hover:bg-emerald-400 dark:bg-emerald-500"
            >
                {{ Lang::get('migration::index.start_new') }}
            </a>
        </div>
    @else
        <header class="flex items-baseline justify-between gap-4">
            <h1 class="text-2xl font-semibold text-slate-900 tracking-tight dark:text-slate-100">{{ Lang::get('migration::index.heading') }}</h1>
            <a
                href="{{ route('migrations.new') }}"
                class="inline-flex items-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:hover:bg-emerald-400 dark:bg-emerald-500"
            >
                {{ Lang::get('migration::index.start_new') }}
            </a>
        </header>

        <ul class="divide-y divide-slate-200 rounded-md border border-slate-200 dark:divide-slate-700 dark:border-slate-700">
            @foreach ($runs as $run)
                <li class="flex items-center justify-between gap-4 px-4 py-3">
                    <div class="flex items-center gap-3">
                        <span class="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                            {{ $this->formatLabel($run->source_product) }}
                        </span>
                        <span class="text-sm text-slate-500 dark:text-slate-400">{{ substr((string) $run->created_at, 0, 10) }}</span>
                        @if ($run->status === \Modules\Migration\Public\Enums\MigrationRunStatus::Confirmed->value)
                            <span class="inline-flex items-center rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20 dark:text-emerald-400">{{ Lang::get('migration::index.status.confirmed') }}</span>
                        @elseif ($run->status === \Modules\Migration\Public\Enums\MigrationRunStatus::NeedsAttention->value)
                            <span class="inline-flex items-center rounded-md bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-950">{{ Lang::get('migration::index.status.needs_attention') }}</span>
                        @else
                            <span class="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ Lang::get('migration::index.status.parsed') }}</span>
                        @endif
                    </div>

                    @if ($run->status === \Modules\Migration\Public\Enums\MigrationRunStatus::Confirmed->value)
                        <a
                            href="{{ route('migrations.new') }}?reconcile_of={{ $run->id }}"
                            class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:bg-slate-900 dark:bg-slate-950 dark:text-slate-100 dark:border-slate-700"
                        >
                            {{ Lang::get('migration::index.check_updates') }}
                        </a>
                    @else
                        <a
                            href="{{ route('migrations.preview', ['id' => $run->id]) }}"
                            class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:bg-slate-900 dark:bg-slate-950 dark:text-slate-100 dark:border-slate-700"
                        >
                            {{ Lang::get('migration::index.resume_preview') }}
                        </a>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
