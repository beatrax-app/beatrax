<div class="max-w-md mx-auto space-y-12" data-testid="settings-page">
    <header class="space-y-1">
        <h1 class="text-3xl font-semibold tracking-tight text-slate-900">Settings</h1>
        <p class="text-sm text-slate-500">Preferences for how your finances appear in the app.</p>
    </header>

    <form wire:submit="save" class="space-y-12">
        <section class="space-y-2">
            <h2 class="text-xs uppercase tracking-wide text-slate-500">Currency display</h2>
            <div class="space-y-1">
                <label for="defaultCurrencyView" class="block text-sm text-slate-900">Default view on the transactions list</label>
                <select
                    id="defaultCurrencyView"
                    name="defaultCurrencyView"
                    wire:model="defaultCurrencyView"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
                >
                    <option value="eur_only">EUR only</option>
                    <option value="original">Original currency</option>
                </select>
                <p class="text-xs text-slate-500">You can still switch per page from the transactions list.</p>
                @error('defaultCurrencyView')
                    <p class="text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>
        </section>

        <section class="space-y-2">
            <h2 class="text-xs uppercase tracking-wide text-slate-500">Period</h2>
            <div class="space-y-1">
                <label for="periodStartDay" class="block text-sm text-slate-900">Period starts on day</label>
                <input
                    type="number"
                    min="1"
                    max="28"
                    id="periodStartDay"
                    name="periodStartDay"
                    wire:model="periodStartDay"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
                />
                <p class="text-xs text-slate-500">Numbered 1 to 28. Most users keep this on 1 (calendar month). Use 25 if your salary lands on the 25th and you think of "your month" as starting then.</p>
                @error('periodStartDay')
                    <p class="text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>
        </section>

        <div class="space-y-1">
            <button
                type="submit"
                class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-md py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2"
            >
                Save settings
            </button>
            @if ($saved)
                <p wire:transition.duration.4000ms class="text-sm text-emerald-700">Saved.</p>
            @endif
        </div>
    </form>
</div>
