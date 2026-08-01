@use('Modules\Core\Public\Support\Lang')
<div class="space-y-6">
    <header class="space-y-1">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400" data-testid="migration-eyebrow">{{ Lang::get('migration::new.eyebrow') }}</p>
        <h1 class="text-2xl font-semibold text-slate-900 tracking-tight dark:text-slate-100">{{ Lang::get('migration::new.heading') }}</h1>
        <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('migration::new.intro') }}</p>
        @if ($reconcileOf !== null)
            <p class="text-sm text-slate-500 dark:text-slate-400" data-testid="reconcile-context">
                {{ Lang::get('migration::new.reconcile_context', ['product' => $this->formatLabel($sourceProduct)]) }}
            </p>
        @endif
    </header>

    @if ($uploadError !== null)
        {{--
            Req 1 inline error banner — populated by submit() when
            ZipExtractor/StartMigrationRun/CheckForUpdates raises (corrupt
            file, unrecognized format, zip-bomb/zip-slip guard). The
            matching stack trace is also written to the Laravel log.
        --}}
        <aside
            role="alert"
            class="rounded-md border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700 dark:bg-rose-950 dark:border-rose-800 dark:text-rose-200"
            data-testid="migration-upload-error-banner"
        >
            {{ $uploadError }}
        </aside>
    @endif

    <form wire:submit="submit" class="space-y-4">
        <div class="space-y-1">
            <label for="sourceProduct" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('migration::new.source_label') }}</label>
            <select
                id="sourceProduct"
                name="sourceProduct"
                wire:model="sourceProduct"
                @disabled($formatLocked)
                class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700 disabled:cursor-not-allowed disabled:opacity-60"
            >
                <option value="ynab4">YNAB4</option>
                <option value="nynab">New YNAB (nYNAB)</option>
                <option value="actual">Actual Budget</option>
            </select>
            @error('sourceProduct')
                <p class="text-sm text-rose-600 dark:text-rose-500">{{ $message }}</p>
            @enderror
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $this->formatHint() }}</p>
        </div>

        <div class="space-y-1">
            <label for="file" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('migration::new.file_label') }}</label>
            <input
                type="file"
                id="file"
                name="file"
                wire:model="file"
                accept=".zip"
                class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700"
            />
            @error('file')
                <p class="text-sm text-rose-600 dark:text-rose-500">{{ $message }}</p>
            @enderror
        </div>

        <button
            type="submit"
            class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-md py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:hover:bg-emerald-400 dark:bg-emerald-500"
        >
            {{ Lang::get('migration::new.parse_button') }}
        </button>
    </form>
</div>
