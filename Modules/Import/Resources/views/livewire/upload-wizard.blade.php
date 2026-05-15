<div class="space-y-6">
    <header class="space-y-1">
        <h1 class="text-2xl font-semibold text-slate-900 tracking-tight">Upload statement</h1>
        <p class="text-sm text-slate-500">Drop in an ASN, ICS, or PayPal export.</p>
        <p class="sr-only" id="upload-statement-mime-hint">That file doesn't look like a supported statement export. Drop in an ASN CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, or ICS PDF.</p>
    </header>

    <form wire:submit="submit" class="space-y-4">
        <div class="space-y-1">
            <label for="issuer" class="block text-sm text-slate-900">Source</label>
            <select
                id="issuer"
                name="issuer"
                wire:model.live="issuer"
                class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
            >
                <option value="asn">ASN</option>
                <option value="ics">ICS</option>
                <option value="paypal">PayPal</option>
            </select>
            @error('issuer')
                <p class="text-sm text-rose-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="space-y-1" aria-live="polite">
            <label for="sourceFormat" class="block text-sm text-slate-900">Format</label>
            <select
                id="sourceFormat"
                name="sourceFormat"
                wire:model="sourceFormat"
                class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
            >
                @foreach ($this->availableFormats() as $fmt)
                    <option value="{{ $fmt['value'] }}">{{ $fmt['label'] }}</option>
                @endforeach
            </select>
            @error('sourceFormat')
                <p class="text-sm text-rose-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="space-y-1">
            <label for="file" class="block text-sm text-slate-900">File</label>
            <input
                type="file"
                id="file"
                name="file"
                wire:model="file"
                accept=".csv,.xml,.sta,.mt940,.940,.txt,.pdf"
                class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
            />
            @error('file')
                <p class="text-sm text-rose-600">{{ $message }}</p>
            @enderror
        </div>

        <button
            type="submit"
            class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-md py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2"
        >
            Upload statement
        </button>
    </form>
</div>
