<div class="space-y-6">
    <header class="space-y-1">
        <h1 class="text-2xl font-semibold text-slate-900 tracking-tight">Upload statement</h1>
        <p class="text-sm text-slate-500">Drop in an ASN CSV export.</p>
        <p class="sr-only" id="upload-statement-mime-hint">That file doesn't look like a CSV. Drop in the ASN CSV export you downloaded from the ASN portal.</p>
    </header>

    <form wire:submit="submit" class="space-y-4">
        <div class="space-y-1">
            <label for="sourceFormat" class="block text-sm text-slate-900">Source format</label>
            <select
                id="sourceFormat"
                name="sourceFormat"
                wire:model="sourceFormat"
                class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
            >
                <option value="asn-csv">ASN CSV</option>
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
                accept=".csv"
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
