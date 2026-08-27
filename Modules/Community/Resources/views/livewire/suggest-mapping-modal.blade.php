@use('Modules\Core\Public\Support\Lang')
{{-- Suggest-mapping modal.

     Renders inside a Flux <flux:modal name="suggest-mapping" dismissible>
     wrapper. Mounted globally in app.blade.php; opens via the
     `suggest-mapping:open` Livewire event (rawDescription param fills
     the pattern field). Submit builds a GitHub Compare URL and hands
     it to OpenExternalUrlAction, which delegates to the NativePHP
     Shell contract. On success the modal raises a `toast` and dispatches
     `modal-close`. On failure the inline error renders above the footer
     and the modal stays open. --}}

<div>
    <flux:modal name="suggest-mapping" dismissible>
        <div class="space-y-6">
            <flux:heading size="lg">{{ Lang::get('community::suggest.heading') }}</flux:heading>

            <p class="text-sm text-slate-700 dark:text-slate-300">
                {{ Lang::get('community::suggest.intro') }}
            </p>

            <form wire:submit="submit" class="space-y-4">
                <x-core::form-field
                    field-id="suggest-pattern"
                    name="pattern"
                    :label="Lang::get('community::suggest.pattern')"
                    wire:model.live="pattern"
                    readonly
                    class="font-mono"
                />

                <x-core::form-field
                    field-id="suggest-name"
                    name="name"
                    :label="Lang::get('community::suggest.name')"
                    wire:model.live="name"
                    :placeholder="Lang::get('community::suggest.name_placeholder')"
                />

                <div class="grid grid-cols-2 gap-4">
                    <x-core::form-field
                        field-id="suggest-category"
                        name="category"
                        :label="Lang::get('community::suggest.category')"
                        wire:model.live="category"
                        :placeholder="Lang::get('community::suggest.category_placeholder')"
                    />

                    <x-core::form-field
                        field-id="suggest-region"
                        name="region"
                        type="select"
                        :label="Lang::get('community::suggest.region')"
                        wire:model.live="region"
                    >
                        @foreach ($regionOptions as $regionCode => $regionLabel)
                            <option value="{{ $regionCode }}">{{ $regionLabel }}</option>
                        @endforeach
                        <option value="">{{ Lang::get('community::suggest.regions.other') }}</option>
                    </x-core::form-field>
                </div>

                <div class="space-y-1">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        {{ Lang::get('community::suggest.yaml_preview') }}
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
                            '  - pattern: &quot;' + (pattern || '') + '&quot;\n' +
                            '    name: &quot;' + (name || '') + '&quot;\n' +
                            ((category && category.length)
                                ? '    category: &quot;' + category + '&quot;\n'
                                : '') +
                            '    region: &quot;' + (region || '') + '&quot;\n' +
                            '    contributor: &quot;user&quot;\n'
                        "
                        class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-xs font-mono text-slate-900 whitespace-pre dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100"
                    ></pre>
                </div>

                @if ($submitError !== '')
                    <p class="text-sm text-rose-600 dark:text-rose-400">{{ $submitError }}</p>
                @endif

                <div class="flex items-center justify-end gap-2">
                    <x-core::secondary-button wire:click="cancel">{{ Lang::get('community::suggest.cancel') }}</x-core::secondary-button>
                    <button
                        type="submit"
                        class="inline-flex items-center rounded-md bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700 focus-visible:ring-offset-2 dark:bg-emerald-700 dark:hover:bg-emerald-800"
                    >{{ Lang::get('community::suggest.submit') }}</button>
                </div>
            </form>
        </div>
    </flux:modal>
</div>
