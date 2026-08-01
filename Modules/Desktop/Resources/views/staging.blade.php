@use('Modules\Core\Public\Support\Lang')
@php
    /**
     * D-01 / D-02 file-staging page. Shown after the OS hands beatrax
     * a `.csv` / `.eml` file path; the page reads the pending intent
     * and either:
     *
     *   - presents the heading "File received: <name>" and a single
     *     emerald "Start import" CTA (PRESENT state), or
     *   - presents the "We couldn't open that file" empty state with
     *     a link back to the manual Imports page (EMPTY state).
     *
     * Layout shell: the Auth / wizard centered full-screen layout the
     * welcome screen and the setup screen also use — brand mark
     * centered, single calm message, single primary CTA.
     *
     * @var array{path: string, extension: string}|null $pending
     * @var string|null $filename
     * @var string $headingPrefix
     * @var string $buttonLabel
     * @var string $emptyHeading
     * @var string $emptyBody
     */
@endphp
<div class="min-h-screen flex items-center justify-center bg-white dark:bg-slate-950">
    <div class="w-full max-w-md mx-auto px-6 space-y-6 text-center">
        <div class="flex justify-center">
            {{-- Brand mark — same surface the welcome / login screens use (D-18). --}}
            <img
                src="{{ asset('icon.png') }}"
                alt="beatrax"
                class="h-20 w-20"
            />
        </div>

        @if ($pending !== null && $filename !== null)
            <header class="space-y-2">
                <h1 class="text-2xl font-semibold text-slate-900 tracking-tight dark:text-slate-100">
                    {{ $headingPrefix }}{{ $filename }}
                </h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ $pending['extension'] === 'csv'
                        ? Lang::get('desktop::screens.staging.csv_subtitle')
                        : Lang::get('desktop::screens.staging.eml_subtitle') }}
                </p>
            </header>

            <button
                type="button"
                wire:click="startImport"
                class="inline-block w-full bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-md py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:bg-emerald-500 dark:hover:bg-emerald-400"
            >
                {{ $buttonLabel }}
            </button>
        @else
            <header class="space-y-2">
                <h1 class="text-2xl font-semibold text-slate-900 tracking-tight dark:text-slate-100">
                    {{ $emptyHeading }}
                </h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ $emptyBody }}
                </p>
            </header>

            <a
                href="{{ route('imports.new') }}"
                class="inline-block w-full bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-md py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:bg-emerald-500 dark:hover:bg-emerald-400"
            >
                {{ Lang::get('desktop::screens.staging.open_imports') }}
            </a>
        @endif
    </div>
</div>
