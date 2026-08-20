@use('Modules\Core\Public\Support\Lang')
<div class="min-h-screen bg-white py-12 dark:bg-slate-950">
    <div class="max-w-xl mx-auto px-6 space-y-6">
        <x-core::page-header
            :title="Lang::get('auth::recovery_codes.title')"
            :subtitle="Lang::get('auth::recovery_codes.subtitle')"
        />

        {{-- One column on a phone, two from 640px.

             Two columns fit the ten codes on one screen without scrolling,
             which is why they were there — but they do not fit a CODE. At
             411px each column is ~180px against a 24-character code, so
             `break-all` did what it was asked and orphaned the last character
             of every one of the ten onto a line of its own:
             "JBDM-KHXE-6BVG-BW4V-2BW" / "J". These are the only way back into
             an account and the screen's own instruction is to write them down.
             A transcription hazard is worse here than a scroll. --}}
        <div aria-live="polite" class="grid grid-cols-1 sm:grid-cols-2 gap-2 sm:gap-3">
            @foreach ($codes as $code)
                <div class="bg-slate-50 border border-slate-200 rounded-md px-2 py-2 sm:px-4 sm:py-3 text-sm sm:text-lg font-semibold font-mono tabular-nums tracking-tight sm:tracking-wider text-slate-900 dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700 break-all" style="font-variant-numeric: tabular-nums;">
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
                    failed: false,
                    codes: @js($codes),
                    payload: @js($downloadPayload),
                    filename: @js($downloadFilename),
                    async copy() {
                        // The webview withholds navigator.clipboard outside a
                        // secure context, and this screen is the one that can
                        // never be shown again — so a copy that quietly does
                        // nothing is the worst possible outcome here.
                        if (await window.beatraxCopy(this.codes.join('\n'))) {
                            this.failed = false;
                            this.copied = true;
                            setTimeout(() => { this.copied = false; }, 2500);

                            return;
                        }

                        this.failed = true;
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
            <p x-show="failed" x-cloak role="alert" aria-live="assertive" class="text-sm text-rose-600 dark:text-rose-400">{{ Lang::get('auth::recovery_codes.copy_failed') }}</p>
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
