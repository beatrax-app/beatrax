@use('Modules\Core\Public\Support\Lang')
{{-- D-06 / UI-SPEC §19: overflow-x:auto on outer wrapper so this surface
     scrolls horizontally at phone width rather than forcing page overflow. --}}
<div class="space-y-6 overflow-x-auto">
    <header class="space-y-1">
        <h1 class="text-2xl font-semibold text-slate-900 tracking-tight dark:text-slate-100">{{ Lang::get('import::upload.heading') }}</h1>
        <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('import::upload.subtitle') }}</p>
        <p class="sr-only" id="upload-statement-mime-hint">{{ Lang::get('import::upload.mime_hint') }}</p>
    </header>

    @if ($uploadError !== null)
        {{--
            Inline parse-time error surface. Populated by submit() when
            the importer raises a typed parser exception (sniff mismatch
            / unsupported PayPal language) or any other Throwable; the
            wizard otherwise would strand the user on a generic Livewire
            "Server error" toast with no actionable detail. The matching
            stack trace is written to the Laravel log via the injected
            LoggerInterface so it surfaces on /dev/logs alongside the
            other ERROR-severity entries.
        --}}
        <aside
            role="alert"
            class="rounded-md border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700 dark:bg-rose-950 dark:border-rose-800 dark:text-rose-200"
            data-testid="upload-error-banner"
        >
            {{ $uploadError }}
        </aside>
    @endif

    <form wire:submit="submit" class="space-y-4">
        <div class="space-y-1">
            <label for="issuer" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('import::upload.source_label') }}</label>
            <select
                id="issuer"
                name="issuer"
                wire:model.live="issuer"
                class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700"
            >
                <option value="asn">ASN</option>
                <option value="ics">ICS</option>
                <option value="paypal">PayPal</option>
                <option value="other-bank">{{ Lang::get('import::upload.issuer_other_bank') }}</option>
                <option value="email-file">{{ Lang::get('import::upload.issuer_email_file') }}</option>
            </select>
            @error('issuer')
                <p class="text-sm text-rose-600 dark:text-rose-500">{{ $message }}</p>
            @enderror
        </div>

        <div class="space-y-1" aria-live="polite">
            <label for="sourceFormat" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('import::upload.format_label') }}</label>
            <select
                id="sourceFormat"
                name="sourceFormat"
                wire:model="sourceFormat"
                class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700"
            >
                @foreach ($this->availableFormats() as $fmt)
                    <option value="{{ $fmt['value'] }}">{{ $fmt['label'] }}</option>
                @endforeach
            </select>
            @error('sourceFormat')
                <p class="text-sm text-rose-600 dark:text-rose-500">{{ $message }}</p>
            @enderror
        </div>

        <div class="space-y-1">
            <label for="file" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('import::upload.file_label') }}</label>
            <x-core::file-input
                id="file"
                name="file"
                wire:model="file"
                accept=".csv,.xml,.sta,.mt940,.940,.txt,.pdf,.eml,.mbox,.zip"
                {{-- The hint has always carried this id; nothing pointed at
                     it, so a screen reader met the list on page entry with no
                     idea which control it described. --}}
                aria-describedby="upload-statement-mime-hint"
            />
            @error('file')
                <p class="text-sm text-rose-600 dark:text-rose-500">{{ $message }}</p>
            @enderror
        </div>

        <button
            type="submit"
            class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-md py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:hover:bg-emerald-400 dark:bg-emerald-500"
        >
            {{ Lang::get('import::upload.submit') }}
        </button>
    </form>

    {{-- Rename counterparty popover. Mounted here so the FirstImportStep
         shell that hosts this wizard inline gets the same click-italic
         rename affordance as the dedicated /imports/{id}/preview page;
         a single instance per page handles every row's rename flow. --}}
    <livewire:import.rename-counterparty-popover />
</div>
