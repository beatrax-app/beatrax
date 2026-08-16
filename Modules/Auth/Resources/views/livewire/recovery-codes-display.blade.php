@use('Modules\Core\Public\Support\Lang')
<div class="min-h-screen bg-white py-12 dark:bg-slate-950">
    <div class="max-w-xl mx-auto px-6 space-y-6">
        <header class="space-y-1">
            <h1 class="text-3xl font-semibold text-slate-900 tracking-tight dark:text-slate-100">{{ Lang::get('auth::recovery_codes.title') }}</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('auth::recovery_codes.subtitle') }}</p>
        </header>

        {{-- Two columns even on a phone. A 20-character code wraps to three
             lines at the old size, so ten of them ran well past a phone
             screen and buried the Save/Continue controls below the fold —
             `tracking-wider` at text-base was costing more width than it
             bought in legibility. --}}
        <div aria-live="polite" class="grid grid-cols-2 gap-2 sm:gap-3">
            @foreach ($codes as $code)
                <div class="bg-slate-50 border border-slate-200 rounded-md px-2 py-2 sm:px-4 sm:py-3 text-xs sm:text-lg font-semibold font-mono tabular-nums tracking-tight sm:tracking-wider text-slate-900 dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700 break-all" style="font-variant-numeric: tabular-nums;">
                    {{ $code }}
                </div>
            @endforeach
        </div>

        {{-- Copy alongside Save: on a phone the clipboard is the route into a
             password manager, which is where these belong anyway, while Save
             writes the same .txt both platforms can keep. --}}
        <div
            class="space-y-2"
            x-data="{
                    copied: false,
                    saved: false,
                    codes: @js($codes),
                    payload: @js($downloadPayload),
                    filename: @js($downloadFilename),
                    copy() {
                        if (! navigator.clipboard) {
                            return;
                        }
                        navigator.clipboard.writeText(this.codes.join('\n')).then(() => {
                            this.copied = true;
                            setTimeout(() => { this.copied = false; }, 2500);
                        });
                    },
                    save() {
                        const url = URL.createObjectURL(new Blob([this.payload], { type: 'text/plain' }));
                        const link = document.createElement('a');
                        link.href = url;
                        link.download = this.filename;
                        document.body.appendChild(link);
                        link.click();
                        link.remove();
                        setTimeout(() => URL.revokeObjectURL(url), 1000);
                        this.saved = true;
                    },
                }"
        >
            <div class="flex flex-col gap-2 sm:flex-row">
                <button
                    type="button"
                    x-on:click="copy()"
                    class="min-h-[44px] flex-1 rounded-md bg-slate-100 px-3 py-2 text-sm font-medium text-slate-900 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:bg-slate-700 dark:bg-slate-800 dark:text-slate-100"
                    x-text="copied ? @js(Lang::get('auth::recovery_codes.copied')) : @js(Lang::get('auth::recovery_codes.copy'))"
                >{{ Lang::get('auth::recovery_codes.copy') }}</button>

                {{-- No wire:click: a Livewire round-trip here can 419 on an
                     expired page, and losing this one is unrecoverable — the
                     codes are never shown again. Saving in the browser needs
                     no server at all. --}}
                <button
                    type="button"
                    x-on:click="save()"
                    data-testid="recovery-codes-download"
                    class="min-h-[44px] flex-1 rounded-md bg-slate-100 px-3 py-2 text-sm font-medium text-slate-900 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:bg-slate-700 dark:bg-slate-800 dark:text-slate-100"
                >
                    {{ Lang::get('auth::recovery_codes.download') }}
                </button>

            </div>

            <p x-show="saved" x-cloak aria-live="polite" class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('auth::recovery_codes.saved_as', ['username' => $username]) }}</p>
        </div>

        <label class="flex items-start gap-2">
            <input type="checkbox" wire:model.live="confirmed" class="mt-1 rounded border-slate-300 dark:border-slate-600">
            <span class="text-sm text-slate-700 dark:text-slate-300">{{ Lang::get('auth::recovery_codes.confirm') }}</span>
        </label>

        <button
            type="button"
            wire:click="continueAfterSave"
            @disabled(! $confirmed)
            aria-disabled="{{ $confirmed ? 'false' : 'true' }}"
            @class([
                'w-full rounded-md py-2 text-sm font-medium text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:focus-visible:ring-emerald-500',
                'bg-emerald-600 hover:bg-emerald-700 dark:bg-emerald-500 dark:hover:bg-emerald-400' => $confirmed,
                'bg-emerald-600/50 cursor-not-allowed dark:bg-emerald-500/40' => ! $confirmed,
            ])
        >
            {{ Lang::get('auth::recovery_codes.continue') }}
        </button>
    </div>
</div>
