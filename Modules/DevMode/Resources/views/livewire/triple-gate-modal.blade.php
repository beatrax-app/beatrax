{{-- Triple-gate modal.

     Rose-tinted header (rose-500 family). dismissible="false" so
     click-outside / Esc does NOT close the modal — the operator
     must explicitly Cancel or pass all three gates. Mounted
     globally in dev-shell.blade.php; dispatched via the
     `triple-gate:open` Livewire event from the runner page's
     per-row Re-run affordance, the queue inspector, and the
     command palette.

     Server enforcement is in TripleGateModal::confirm():
       Gate 1 — DevModeFlag->isOn()
       Gate 2 — session('dev_mode.advanced') === true
       Gate 3 — hash_equals('Beatrax', $typed)
     The disabled-until-match primary button below is purely
     cosmetic.
--}}
@use('Modules\Core\Public\Support\Lang')
<div>
    <flux:modal name="triple-gate" :dismissible="false">
        <div class="space-y-6">
            <flux:heading size="lg" class="text-rose-700 dark:text-rose-400">
                {{ Lang::get('dev::triple_gate.heading') }}
            </flux:heading>

            <div class="space-y-3">
                <p class="text-sm text-slate-700 dark:text-slate-300">
                    {{ Lang::get('dev::triple_gate.intro') }}
                </p>

                <div class="gate-cmd rounded-md border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-sm text-slate-900 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100">
                    <span aria-hidden="true">$ </span>
                    php artisan {{ $command }}
                    @foreach ($resolvedArgs as $key => $value)
                        @if (str_starts_with((string) $key, '--'))
                            {{ $key }}={{ $value }}
                        @else
                            {{ $value }}
                        @endif
                    @endforeach
                </div>

                @if ($gateError === 'dev_mode_off')
                    <p class="text-sm text-rose-600 dark:text-rose-500">{!! Lang::get('dev::triple_gate.error_dev_mode_off_html') !!}</p>
                @elseif ($gateError === 'advanced_off')
                    <p class="text-sm text-rose-600 dark:text-rose-500">{{ Lang::get('dev::triple_gate.error_advanced_off') }}</p>
                @elseif ($gateError === 'app_name_mismatch')
                    <p class="text-sm text-rose-600 dark:text-rose-500">{{ Lang::get('dev::triple_gate.error_app_name_mismatch') }}</p>
                @endif

                <form wire:submit="confirm" class="space-y-4">
                    <div class="space-y-1" x-data="{ typed: @entangle('typed') }">
                        <label for="triple-gate-typed" class="block text-sm text-slate-900 dark:text-slate-100">
                            {!! Lang::get('dev::triple_gate.type_to_confirm_html') !!}
                        </label>
                        <input
                            type="text"
                            id="triple-gate-typed"
                            wire:model.live="typed"
                            x-model="typed"
                            autocomplete="off"
                            autocorrect="off"
                            spellcheck="false"
                            class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-mono text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500 focus-visible:ring-offset-2 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100"
                        />

                        <div class="flex items-center justify-end gap-2 pt-2">
                            <button
                                type="button"
                                wire:click="cancel"
                                class="inline-flex items-center rounded-md px-4 py-2 text-sm font-medium text-slate-500 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-400 dark:hover:bg-slate-800"
                            >{{ Lang::get('dev::triple_gate.cancel') }}</button>
                            <button
                                type="submit"
                                class="pill-btn danger inline-flex items-center rounded-md bg-rose-600 px-4 py-2 text-sm font-medium text-white hover:bg-rose-700 disabled:cursor-not-allowed disabled:bg-slate-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2 dark:bg-rose-500 dark:hover:bg-rose-400 dark:disabled:bg-slate-600"
                                x-bind:disabled="typed !== 'Beatrax'"
                                wire:loading.attr="disabled"
                            >
                                <span wire:loading.remove wire:target="confirm">{{ Lang::get('dev::triple_gate.run', ['command' => $command]) }}</span>
                                <span wire:loading wire:target="confirm">{{ Lang::get('dev::triple_gate.running') }}</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </flux:modal>
</div>
