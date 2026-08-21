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
            Inline error banner — populated by submit() when
            ZipExtractor/StartMigrationRun/CheckForUpdates raises (corrupt
            file, unrecognized format, zip-bomb/zip-slip guard). The
            matching stack trace is also written to the Laravel log.
        --}}
        <x-core::alert tone="danger" role="alert"
            data-testid="migration-upload-error-banner">
            {{ $uploadError }}
        </x-core::alert>
    @endif

    <form wire:submit="submit" class="space-y-4">
        <x-core::form-field
            name="sourceProduct"
            type="select"
            :label="Lang::get('migration::new.source_label')"
            :hint="$this->formatHint()"
            wire:model="sourceProduct"
            :disabled="$formatLocked"
            class="disabled:cursor-not-allowed disabled:opacity-60"
        >
            <option value="ynab4">YNAB4</option>
            <option value="nynab">New YNAB (nYNAB)</option>
            <option value="actual">Actual Budget</option>
        </x-core::form-field>

        <div class="space-y-1">
            <label for="file" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('migration::new.file_label') }}</label>
            <x-core::file-input
                id="file"
                name="file"
                wire:model="file"
                accept=".zip"
            />
            @error('file')
                <p class="text-sm text-rose-600 dark:text-rose-500">{{ $message }}</p>
            @enderror
        </div>

        <x-core::primary-button>
            {{ Lang::get('migration::new.parse_button') }}
        </x-core::primary-button>
    </form>
</div>
