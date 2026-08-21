@php
    /**
     * First-close prompt — Quit vs Keep-in-tray.
     *
     * flux:modal chosen over a native dialog so it inherits the dark
     * theme (UI-SPEC Component Inventory).
     *
     * Accessibility (UI-SPEC): both action buttons render at h-12
     * (48 px) to clear the Apple HIG / WCAG 2.5.5 minimum target-size
     * floor for native chrome.
     *
     * @var string $title
     * @var string $body
     * @var string $buttonQuit
     * @var string $buttonKeepInTray
     * @var string $checkboxRemember
     * @var string $modalName
     */
@endphp

<div>
    <flux:modal :name="$modalName" class="md:max-w-lg" :dismissible="false">
        <div class="space-y-6">
            <flux:heading size="lg">{{ $title }}</flux:heading>

            <p class="text-sm text-slate-500 dark:text-slate-400">
                {{ $body }}
            </p>

            <x-core::checkbox-field :label="$checkboxRemember" wire:model="rememberChoice" />

            <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                {{--
                    "Quit Beatrax" — destructive, rose-styled, NOT
                    default-focused (UI-SPEC). The heavier choice
                    silently stops the bundled queue worker and the
                    timer-based email scan, so it deliberately is not
                    the visually-dominant button.
                --}}
                <button
                    type="button"
                    wire:click="chooseQuit"
                    class="h-12 rounded-md border border-rose-200 bg-white px-5 text-sm font-medium text-rose-700 hover:bg-rose-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-rose-900 dark:text-rose-400 dark:hover:bg-rose-950/40"
                >
                    {{ $buttonQuit }}
                </button>

                {{--
                    "Keep running in the tray" — primary action,
                    emerald-600, default-focused (autofocus). Keeps
                    the worker + scheduler alive so the
                    partner's background work continues uninterrupted.
                --}}
                <button
                    type="button"
                    wire:click="chooseKeepInTray"
                    autofocus
                    class="h-12 rounded-md bg-emerald-600 px-5 text-sm font-medium text-white hover:bg-emerald-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:bg-emerald-500 dark:hover:bg-emerald-400"
                >
                    {{ $buttonKeepInTray }}
                </button>
            </div>
        </div>
    </flux:modal>
</div>
