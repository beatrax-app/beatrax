@use('Modules\Core\Public\Support\Lang')
<div class="space-y-6">
    @if ($runs->isEmpty())
        {{-- level="h1": with no runs yet this block is the whole page, so its
             heading is the page's only h1 — the header below it never renders. --}}
        <x-core::empty-state
            level="h1"
            class="py-16"
            :heading="Lang::get('migration::index.empty_heading')"
            :body="Lang::get('migration::index.intro')"
        >
            <a
                href="{{ route('migrations.new') }}"
                class="pill-btn-primary inline-flex items-center rounded-md bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700 focus-visible:ring-offset-2 dark:hover:bg-emerald-800 dark:bg-emerald-700"
            >
                {{ Lang::get('migration::index.start_new') }}
            </a>
        </x-core::empty-state>
    @else
        <header class="flex flex-wrap items-baseline justify-between gap-4">
            <x-core::page-heading>{{ Lang::get('migration::index.heading') }}</x-core::page-heading>
            <a
                href="{{ route('migrations.new') }}"
                class="pill-btn-primary inline-flex items-center rounded-md bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700 focus-visible:ring-offset-2 dark:hover:bg-emerald-800 dark:bg-emerald-700"
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
                        @if ($run->status === \Modules\Migration\Internal\Enums\MigrationRunStatus::Confirmed->value)
                            <span class="inline-flex items-center rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20 dark:text-emerald-400">{{ Lang::get('migration::index.status.confirmed') }}</span>
                        @elseif ($run->status === \Modules\Migration\Internal\Enums\MigrationRunStatus::NeedsAttention->value)
                            <span class="inline-flex items-center rounded-md bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-950">{{ Lang::get('migration::index.status.needs_attention') }}</span>
                        @else
                            <span class="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ Lang::get('migration::index.status.parsed') }}</span>
                        @endif
                    </div>

                    @if ($run->status === \Modules\Migration\Internal\Enums\MigrationRunStatus::Confirmed->value)
                        <x-core::secondary-button
                            href="{{ route('migrations.new', ['reconcile_of' => $run->id]) }}"
                            size="sm"
                        >
                            {{ Lang::get('migration::index.check_updates') }}
                        </x-core::secondary-button>
                    @else
                        <x-core::secondary-button
                            :href="route('migrations.preview', ['id' => $run->id])"
                            size="sm"
                        >
                            {{ Lang::get('migration::index.resume_preview') }}
                        </x-core::secondary-button>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
