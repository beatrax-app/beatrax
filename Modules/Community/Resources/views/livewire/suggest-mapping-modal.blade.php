{{-- Suggest-mapping modal.

     Renders inside a Flux <flux:modal name="suggest-mapping" dismissible>
     wrapper. Mounted globally in app.blade.php; opens via the
     `suggest-mapping:open` Livewire event (rawDescription param fills
     the pattern field). Submit builds a GitHub Compare URL and hands
     it to OpenExternalUrlAction, which delegates to the NativePHP
     Shell contract. On success the modal dispatches `toast.show` and
     `modal-hide`. On failure the inline error renders above the footer
     and the modal stays open. --}}

<div>
    <flux:modal name="suggest-mapping" dismissible>
        <div class="space-y-6">
            <flux:heading size="lg">Suggest a mapping</flux:heading>

            <p class="text-sm text-slate-700 dark:text-slate-300">
                Opens GitHub in your browser so you can submit the suggestion as a draft PR. Your name and email never leave this machine.
            </p>

            <form wire:submit="submit" class="space-y-4">
                <div class="space-y-1">
                    <label for="suggest-pattern" class="block text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        Pattern
                    </label>
                    <input
                        type="text"
                        id="suggest-pattern"
                        wire:model.live="pattern"
                        readonly
                        class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-mono text-slate-900 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100"
                    />
                </div>

                <div class="space-y-1">
                    <label for="suggest-name" class="block text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        Friendly name
                    </label>
                    <input
                        type="text"
                        id="suggest-name"
                        wire:model.live="name"
                        placeholder="e.g. Albert Heijn"
                        class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100"
                    />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-1">
                        <label for="suggest-category" class="block text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                            Category (optional)
                        </label>
                        <input
                            type="text"
                            id="suggest-category"
                            wire:model.live="category"
                            placeholder="e.g. Groceries"
                            class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100"
                        />
                    </div>

                    <div class="space-y-1">
                        <label for="suggest-region" class="block text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                            Region
                        </label>
                        <select
                            id="suggest-region"
                            wire:model.live="region"
                            class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100"
                        >
                            <option value="NL">NL — Netherlands</option>
                            <option value="BE">BE — Belgium</option>
                            <option value="DE">DE — Germany</option>
                            <option value="FR">FR — France</option>
                            <option value="OTHER">Other</option>
                        </select>
                    </div>
                </div>

                <div class="space-y-1">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        YAML preview
                    </p>
                    {{-- YAML preview: the template lives on a data attribute
                         so the Alpine x-data block can interpolate the four
                         reactive fields without a multi-quote inline
                         expression. The `entangle` calls bind to the
                         Livewire properties so any keystroke updates the
                         rendered <pre> with no server roundtrip. --}}
                    <pre
                        x-data="{
                            pattern: $wire.entangle('pattern'),
                            name: $wire.entangle('name'),
                            category: $wire.entangle('category'),
                            region: $wire.entangle('region'),
                        }"
                        x-text="
                            'entries:\n' +
                            '  - pattern: "' + (pattern || '') + '"\n' +
                            '    name: "' + (name || '') + '"\n' +
                            ((category && category.length)
                                ? '    category: "' + category + '"\n'
                                : '') +
                            '    region: "' + (region || 'NL') + '"\n' +
                            '    contributor: "user"\n'
                        "
                        class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-xs font-mono text-slate-900 whitespace-pre dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100"
                    ></pre>
                </div>

                @if ($submitError !== '')
                    <p class="text-sm text-rose-600 dark:text-rose-400">{{ $submitError }}</p>
                @endif

                <div class="flex items-center justify-end gap-2">
                    <button
                        type="button"
                        wire:click="cancel"
                        class="inline-flex items-center rounded-md border border-slate-200 px-4 py-2 text-sm text-slate-900 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-100 dark:hover:bg-slate-900"
                    >Cancel</button>
                    <button
                        type="submit"
                        class="inline-flex items-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:bg-emerald-500 dark:hover:bg-emerald-400"
                    >Submit as draft PR</button>
                </div>
            </form>
        </div>
    </flux:modal>
</div>
