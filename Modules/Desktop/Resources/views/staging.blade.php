@use('Modules\Core\Public\Support\Lang')
@php
    /**
     * D-01 / D-02 file-staging page. Shown after the OS hands Beatrax
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
            {{-- Brand mark — same surface the welcome / login screens use. --}}
            <img
                src="{{ asset('icon.png') }}"
                alt="Beatrax"
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

            <x-core::primary-button type="button" wire:click="startImport">
                {{ $buttonLabel }}
            </x-core::primary-button>
        @else
            <header class="space-y-2">
                <h1 class="text-2xl font-semibold text-slate-900 tracking-tight dark:text-slate-100">
                    {{ $emptyHeading }}
                </h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ $emptyBody }}
                </p>
            </header>

            <x-core::primary-button href="{{ route('imports.new') }}">
                {{ Lang::get('desktop::screens.staging.open_imports') }}
            </x-core::primary-button>
        @endif
    </div>
</div>
