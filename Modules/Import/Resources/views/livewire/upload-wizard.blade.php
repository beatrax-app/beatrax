@use('Modules\Core\Public\Support\Lang')
@use('Modules\Import\Internal\Enums\ImportType')
{{-- UI-SPEC §19: overflow-x:auto on outer wrapper so this surface
     scrolls horizontally at phone width rather than forcing page overflow. --}}
<div class="space-y-6 overflow-x-auto">
    <header class="space-y-1">
        <x-core::page-heading>{{ Lang::get('import::upload.heading') }}</x-core::page-heading>
        <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('import::upload.subtitle') }}</p>
        <p class="sr-only" id="upload-statement-mime-hint">{{ Lang::get('import::upload.mime_hint') }}</p>
    </header>

    @if ($uploadError !== null)
        {{--
            Inline parse-time error surface. Populated by submit() when
            the importer raises a typed parser exception (sniff mismatch
            / unsupported PayPal language) or any other Throwable; the
            wizard otherwise would strand the user on a generic Livewire
            "Server error" toast with no actionable detail. The matching
            stack trace is written to the Laravel log via the injected
            LoggerInterface so it surfaces on /dev/logs alongside the
            other ERROR-severity entries.
        --}}
        <x-core::alert tone="danger" role="alert"
            data-testid="upload-error-banner">
            {{ $uploadError }}
        </x-core::alert>
    @endif

    <form wire:submit="submit" class="space-y-4">
        <x-core::form-field
            name="importType"
            type="select"
            :label="Lang::get('import::upload.type_label')"
            wire:model.live="importType"
        >
            @foreach (ImportType::cases() as $type)
                <option value="{{ $type->value }}">{{ $type->label() }}</option>
            @endforeach
        </x-core::form-field>

        {{-- aria-live wraps the field: the option list is rebuilt whenever the
             import type above changes, and that swap has to be announced. --}}
        <div aria-live="polite">
            <x-core::form-field
                name="sourceFormat"
                type="select"
                :label="Lang::get('import::upload.format_label')"
                wire:model.live="sourceFormat"
            >
                @foreach ($this->availableFormats() as $fmt)
                    <option value="{{ $fmt['value'] }}">{{ $fmt['label'] }}</option>
                @endforeach
            </x-core::form-field>

            {{-- Inside the aria-live region on purpose: this line is only ever
                 written because the screen changed the select the reader is
                 looking at, and a silent change is the surprise it exists to
                 prevent. --}}
            @if ($formatNotice !== null)
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400" data-testid="format-notice">
                    {{ $formatNotice }}
                </p>
            @endif
        </div>

        <div class="space-y-1">
            <label for="file" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('import::upload.file_label') }}</label>
            <x-core::file-input
                id="file"
                name="file"
                wire:model.live="file"
                accept=".csv,.xml,.sta,.mt940,.940,.txt,.pdf,.eml,.mbox"
                {{-- The hint has always carried this id; nothing pointed at
                     it, so a screen reader met the list on page entry with no
                     idea which control it described. --}}
                aria-describedby="upload-statement-mime-hint"
            />
            @error('file')
                <p class="text-sm text-rose-600 dark:text-rose-500">{{ $message }}</p>
            @enderror
        </div>

        <x-core::primary-button>
            {{ Lang::get('import::upload.submit') }}
        </x-core::primary-button>
    </form>

    {{-- Rename counterparty popover. Mounted here so the FirstImportStep
         shell that hosts this wizard inline gets the same click-italic
         rename affordance as the dedicated /imports/{id}/preview page;
         a single instance per page handles every row's rename flow. --}}
    <livewire:import.rename-counterparty-popover />
</div>
