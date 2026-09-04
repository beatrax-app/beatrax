@use('Modules\Core\Public\Support\Lang')
{{-- The system bars are painted over this screen: without the bottom inset
     the Android navigation bar covers the lower half of "Continue to Beatrax",
     which is the only way off a screen shown exactly once. --}}
{{-- .safe-screen, all four edges, because no bar is drawn above this screen
     any more: the menubar and the search box are withheld from a first-run
     ceremony, so the status bar has nothing else reserving it. The stylesheet
     zeroes the top inset again for any document that does carry a .top-bar,
     which is what keeps the class correct in both shapes. The rhythm moves
     inside onto the column, where it adds to the seam rather than replacing it,
     and takes the shared step in doing so: the pt-12 it replaces was a band of
     its own that the mx-auto rule could not see, because pt is not py. --}}
<div class="safe-screen min-h-screen bg-white dark:bg-slate-950">
    <div class="max-w-xl mx-auto px-4 sm:px-6 py-6 space-y-6">
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
                    ...copyToClipboard(@js($codes).join('\n')),
                    saved: false,
                    saveFailed: false,
                    payload: @js($downloadPayload),
                    filename: @js($downloadFilename),
                    exportUrl: @js($exportUrl),
                    async save() {
                        // A phone: hand the file to the OS share sheet and
                        // report what the endpoint says. The blob path below
                        // writes nothing in a WebView, with no error and no
                        // console entry, so claiming success there is a lie.
                        if (this.exportUrl) {
                            try {
                                const response = await fetch(this.exportUrl, { headers: { 'Accept': 'application/json' } });
                                const result = await response.json();

                                this.saved = result.saved === true;
                                this.saveFailed = result.saved !== true;
                            } catch (e) {
                                this.saved = false;
                                this.saveFailed = true;
                            }

                            return;
                        }

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

            {{-- Two different truths, so neither is a promise the platform does
                 not keep. A browser download manager puts the file where the
                 reader named it. A phone has no such place: the endpoint writes
                 into the app's own container — unreachable in Files, and
                 destroyed by the reinstall these codes exist to survive — and
                 hands it to the OS, which on iOS surfaced nothing at all. --}}
            <p x-show="saved && ! exportUrl" x-cloak aria-live="polite" class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('auth::recovery_codes.saved_as', ['username' => $downloadSlug]) }}</p>
            <p x-show="saved && exportUrl" x-cloak aria-live="polite" class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('auth::recovery_codes.saved_native') }}</p>
            {{-- Two failures, two sentences. They shared one flag, so a refused
                 save told the reader the copy had failed — and the reader who
                 then reaches for the clipboard instead of a pen leaves a screen
                 shown exactly once with no copy of the codes at all. --}}
            <p x-show="failed" x-cloak role="alert" aria-live="assertive" class="text-sm text-rose-600 dark:text-rose-400">{{ Lang::get('auth::recovery_codes.copy_failed') }}</p>
            <p x-show="saveFailed" x-cloak role="alert" aria-live="assertive" class="text-sm text-rose-600 dark:text-rose-400">{{ Lang::get('auth::recovery_codes.save_failed') }}</p>
        </div>

        <x-core::checkbox-field
            align="start"
            :label="Lang::get('auth::recovery_codes.confirm')"
            wire:model.live="confirmed"
        />

        <button
            type="button"
            wire:click="continueAfterSave"
            @disabled(! $confirmed)
            aria-disabled="{{ $confirmed ? 'false' : 'true' }}"
            @class([
                'w-full rounded-md py-2 text-sm font-medium text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700 focus-visible:ring-offset-2 dark:focus-visible:ring-emerald-500',
                'bg-emerald-700 hover:bg-emerald-800 dark:bg-emerald-700 dark:hover:bg-emerald-800' => $confirmed,
                'bg-emerald-700/50 cursor-not-allowed dark:bg-emerald-700/40' => ! $confirmed,
            ])
        >
            {{ Lang::get('auth::recovery_codes.continue') }}
        </button>
    </div>
</div>
