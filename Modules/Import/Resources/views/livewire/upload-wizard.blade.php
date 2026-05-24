<div class="space-y-6">
    <header class="space-y-1">
        <h1 class="text-2xl font-semibold text-slate-900 tracking-tight dark:text-slate-100">Upload statement</h1>
        <p class="text-sm text-slate-500 dark:text-slate-400">Drop in an ASN, ICS, PayPal export, or an email receipt file.</p>
        <p class="sr-only" id="upload-statement-mime-hint">That file doesn't look like a supported statement export. Drop in an ASN CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, ICS PDF, an email message (.eml), or a mailbox archive (.mbox).</p>
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
            <label for="issuer" class="block text-sm text-slate-900 dark:text-slate-100">Source</label>
            <select
                id="issuer"
                name="issuer"
                wire:model.live="issuer"
                class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700"
            >
                <option value="asn">ASN</option>
                <option value="ics">ICS</option>
                <option value="paypal">PayPal</option>
                <option value="email-file">Email file (.eml, .mbox)</option>
            </select>
            @error('issuer')
                <p class="text-sm text-rose-600 dark:text-rose-500">{{ $message }}</p>
            @enderror
        </div>

        <div class="space-y-1" aria-live="polite">
            <label for="sourceFormat" class="block text-sm text-slate-900 dark:text-slate-100">Format</label>
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
            <label for="file" class="block text-sm text-slate-900 dark:text-slate-100">File</label>
            <input
                type="file"
                id="file"
                name="file"
                wire:model="file"
                accept=".csv,.xml,.sta,.mt940,.940,.txt,.pdf,.eml,.mbox,.zip"
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
            Upload statement
        </button>
    </form>
</div>
