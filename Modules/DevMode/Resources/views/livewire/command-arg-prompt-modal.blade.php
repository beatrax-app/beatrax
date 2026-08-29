{{-- Arg-prompt modal for SAFE-tier artisan commands with required
     or optional arguments. The palette dispatches `command-args:prompt`
     when an operator picks a 'dev' row whose CommandSpec carries
     argsSchema; the runner page's fallback modal does the same.
     Both surfaces converge here so there's a single arg-entry form
     in the project rather than two parallel implementations.

     Stable Flux modal name (`command-args`) — the component's open()
     listener dispatches `modal-show` with that literal name, mirroring
     the chain-drawer fix that removed the Alpine-vs-wire race. --}}
@use('Modules\Core\Public\Support\Lang')
@use('Modules\DevMode\Internal\Enums\ArgType')
<div>
    <flux:modal name="command-args" class="md:w-lg">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ Lang::get('dev::arg_prompt.heading') }}</flux:heading>
                @if ($spec !== null)
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        <span class="font-mono">{{ $spec->name }}</span>
                        @if ($spec->description !== null && $spec->description !== '')
                            — {{ $spec->description }}
                        @endif
                    </p>
                @endif
            </div>

            @if ($submitError !== '')
                <x-core::alert tone="danger" class="px-4 py-3" role="alert"
                    data-testid="command-arg-prompt-error">
                    {{ $submitError }}
                </x-core::alert>
            @endif

            @if ($spec === null)
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ Lang::get('dev::arg_prompt.pick_command') }}
                </p>
            @elseif (count($argSchema) === 0)
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ Lang::get('dev::arg_prompt.no_args') }}
                </p>
            @else
                @php
                    $missingRequired = false;
                    foreach ($argSchema as $arg) {
                        if (! in_array('required', $arg->rules, true)) {
                            continue;
                        }
                        $val = $values[$arg->name] ?? null;
                        if ($val === null || $val === '' || $val === []) {
                            $missingRequired = true;
                            break;
                        }
                    }
                @endphp

                <div class="space-y-4" data-testid="command-arg-prompt-fields">
                    @foreach ($argSchema as $idx => $arg)
                        @php
                            $isRequired = in_array('required', $arg->rules, true);
                            $fieldId = 'arg-field-'.$arg->name;
                        @endphp
                        <div class="space-y-1.5">
                            <label
                                for="{{ $fieldId }}"
                                class="block text-sm font-medium text-slate-900 dark:text-slate-100"
                            >
                                {{ $arg->label !== '' ? $arg->label : $arg->name }}
                                @if ($isRequired)
                                    <span role="img" class="text-rose-600 dark:text-rose-400" aria-label="{{ Lang::get('dev::arg_prompt.required_aria') }}">*</span>
                                @endif
                            </label>

                            @if ($arg->type === ArgType::Boolean)
                                {{-- :x-init, not @if inside the tag: a Blade directive
                                     between a component tag's attributes stops Blade
                                     matching the tag at all, and it ships as dead HTML. --}}
                                <x-core::checkbox-field
                                    :field-id="$fieldId"
                                    :label="Lang::get('dev::arg_prompt.enable')"
                                    wire:model.live="values.{{ $arg->name }}"
                                    :x-init="$idx === 0 ? '$nextTick(() => $el.focus())' : null"
                                    data-testid="arg-input-{{ $arg->name }}"
                                />
                            @elseif ($arg->type === ArgType::Select && is_array($arg->options))
                                <select
                                    id="{{ $fieldId }}"
                                    wire:model.live="values.{{ $arg->name }}"
                                    @if ($idx === 0) x-init="$nextTick(() => $el.focus())" @endif
                                    class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-slate-500 focus:ring-slate-500 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                    data-testid="arg-input-{{ $arg->name }}"
                                >
                                    <option value="">{{ Lang::get('dev::arg_prompt.select_placeholder') }}</option>
                                    @foreach ($arg->options as $optValue => $optLabel)
                                        <option value="{{ $optValue }}">{{ $optLabel }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input
                                    id="{{ $fieldId }}"
                                    type="text"
                                    wire:model.live="values.{{ $arg->name }}"
                                    @if ($arg->placeholder !== null) placeholder="{{ $arg->placeholder }}" @endif
                                    @if ($idx === 0) x-init="$nextTick(() => $el.focus())" x-on:keydown.enter.prevent="$wire.submit()" @endif
                                    class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-500 focus:border-slate-500 focus:ring-slate-500 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100 dark:placeholder:text-slate-400"
                                    data-testid="arg-input-{{ $arg->name }}"
                                />
                            @endif

                            @if ($arg->helpText !== null && $arg->helpText !== '')
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ $arg->helpText }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="flex items-center justify-end gap-2 pt-2">
                <x-core::secondary-button
                    size="sm"
                    wire:click="cancel"
                    data-testid="command-arg-prompt-cancel"
                >{{ Lang::get('dev::arg_prompt.cancel') }}</x-core::secondary-button>
                <x-core::neutral-button
                    size="sm"
                    class="disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="isset($missingRequired) && $missingRequired"
                    :aria-disabled="isset($missingRequired) && $missingRequired ? 'true' : null"
                    wire:click="submit"
                    data-testid="command-arg-prompt-submit"
                >{{ Lang::get('dev::arg_prompt.run_command') }}</x-core::neutral-button>
            </div>
        </div>
    </flux:modal>
</div>
