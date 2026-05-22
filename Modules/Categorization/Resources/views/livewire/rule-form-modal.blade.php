{{-- Rule form modal.

     Renders inside a Flux <flux:modal name="rule-form" dismissible>
     wrapper. Three dropdowns + one text input + one category picker
     + Save/Cancel laid out as a natural-language sentence.

     Mounted globally in app.blade.php; dispatched via the
     `rule-form:open` Livewire event (optional ruleId param hydrates
     edit mode). Save fires `rule-form:saved` then dispatches
     `modal-hide` so any page can listen for the resulting refresh. --}}

<div>
    <flux:modal name="rule-form" dismissible>
        <div class="space-y-6">
            <flux:heading size="lg">
                {{ $isEditMode ? 'Edit rule' : 'New rule' }}
            </flux:heading>

            <form wire:submit="save" class="space-y-6">
                <div class="space-y-4">
                    <p class="text-sm text-slate-700 dark:text-slate-300">
                        When the
                        <select
                            wire:model.live="field"
                            aria-label="Match field"
                            class="ml-1 mr-1 inline-flex rounded-md border border-slate-200 bg-slate-50 px-2 py-1 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100"
                        >
                            <option value="">—</option>
                            <option value="merchant">merchant</option>
                            <option value="description">description</option>
                            <option value="counterparty">counterparty</option>
                        </select>
                        <select
                            wire:model.live="match"
                            aria-label="Match operator"
                            class="mr-1 inline-flex rounded-md border border-slate-200 bg-slate-50 px-2 py-1 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100"
                        >
                            <option value="">—</option>
                            <option value="contains">contains</option>
                            <option value="equals">equals</option>
                            <option value="starts_with">starts with</option>
                        </select>
                        the value
                    </p>

                    <div class="space-y-1">
                        <label for="rule-form-value" class="sr-only">Match value</label>
                        <input
                            type="text"
                            id="rule-form-value"
                            wire:model.lazy="value"
                            placeholder="e.g. SPOTIFY"
                            @if ($errorValue !== '')
                                aria-invalid="true"
                                aria-describedby="rule-form-value-error"
                            @endif
                            class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-mono text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100"
                        />
                        <p class="text-xs text-slate-500 dark:text-slate-400">Matching is case-insensitive. Use the exact text you see in the transaction.</p>
                        @if ($errorValue !== '')
                            <p id="rule-form-value-error" class="text-sm text-rose-600 dark:text-rose-500">{{ $errorValue }}</p>
                        @endif
                    </div>
                </div>

                <div class="space-y-1">
                    <label for="rule-form-category" class="block text-sm text-slate-900 dark:text-slate-100">Assign category</label>
                    <select
                        id="rule-form-category"
                        wire:model.live="categoryId"
                        @if ($errorCategory !== '')
                            aria-invalid="true"
                            aria-describedby="rule-form-category-error"
                        @endif
                        class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100"
                    >
                        <option value="">—</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->path }}</option>
                        @endforeach
                    </select>
                    @if ($errorCategory !== '')
                        <p id="rule-form-category-error" class="text-sm text-rose-600 dark:text-rose-500">{{ $errorCategory }}</p>
                    @endif
                </div>

                <div class="flex items-center justify-end gap-2 pt-2">
                    <button
                        type="button"
                        wire:click="cancel"
                        class="inline-flex items-center rounded-md px-4 py-2 text-sm font-medium text-slate-500 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-400 dark:hover:bg-slate-800"
                    >Cancel</button>
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        @disabled($field === '' || $match === '' || trim($value) === '' || $categoryId === null || $categoryId === 0)
                        class="inline-flex items-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-slate-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:bg-emerald-500 dark:hover:bg-emerald-400 dark:disabled:bg-slate-600"
                    >
                        <span wire:loading.remove wire:target="save">{{ $isEditMode ? 'Save changes' : 'Save rule' }}</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </button>
                </div>
            </form>
        </div>
    </flux:modal>
</div>
