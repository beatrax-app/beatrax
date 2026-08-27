@use('Modules\Core\Public\Navigation\Destination')
@use('Illuminate\View\ComponentAttributeBag')
@use('Modules\Core\Public\Support\Lang')
{{-- `/settings/open-banking` trust surface (19-11, UI-SPEC Surface B). --}}

<div class="max-w-2xl mx-auto space-y-6" data-testid="open-banking-settings-page">
    <a href="{{ Destination::Settings->url() }}" class="text-sm text-slate-500 hover:underline dark:text-slate-400">&larr; {{ Lang::get('openbanking::messages.page.back_link') }}</a>

    <header class="space-y-1">
        <x-core::page-heading level="section">{{ Lang::get('openbanking::messages.page.heading') }}</x-core::page-heading>
        <p class="text-sm text-slate-500 dark:text-slate-400">
            {{ Lang::get('openbanking::messages.page.subtitle') }}
        </p>
    </header>

    @if ($flashMessage !== '')
        <x-core::alert
            :tone="$flashTone === 'error' ? 'danger' : 'positive'"
            role="{{ $flashTone === 'error' ? 'alert' : 'status' }}"
            data-testid="ob-page-flash"
        >{{ $flashMessage }}</x-core::alert>
    @endif

    {{-- ===== B1: header + toggle ===== --}}
    <x-core::card>
        <div class="flex items-start justify-between gap-3">
            <div class="flex-1">
                <p class="text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('openbanking::messages.page.toggle_label') }}</p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    @if ($enabled)
                        {{ Lang::get('openbanking::messages.page.toggle_connected', ['bank' => $bankDisplayName]) }}
                    @else
                        {{ Lang::get('openbanking::messages.page.toggle_off_help') }}
                    @endif
                </p>
            </div>
            <x-core::switch
                :on="$enabled"
                :label="Lang::get('openbanking::messages.page.toggle_label')"
                wire:click="toggleClicked"
                data-testid="ob-toggle"
            />
        </div>
    </x-core::card>

    {{-- ===== Re-confirm CTA — the SCA dance completed but the
         acknowledgement TTL lapsed before mount() could finalize the
         enable. Surface a visible re-confirm instead of a silent no-op. ===== --}}
    @if ($needsReconfirm)
        <x-core::alert
            tone="warning"
            role="alert"
            data-testid="ob-reconfirm-banner"
        >
            <p>{{ Lang::get('openbanking::messages.page.reconfirm_body') }}</p>
            <x-core::neutral-button
                class="mt-3 min-h-[44px]"
                wire:click="reconfirmEnable"
                wire:loading.attr="disabled"
                wire:target="reconfirmEnable"
                data-testid="ob-reconfirm-button"
            >{{ Lang::get('openbanking::messages.page.reconfirm_button') }}</x-core::neutral-button>
        </x-core::alert>
    @endif

    {{-- ===== B5: consent-expiry banner — rendered above the
         transparency panel when consent has expired ===== --}}
    @include('openbanking::partials.open-banking-consent-banner')

    {{-- ===== B4: transparency panel ===== --}}
    @include('openbanking::partials.open-banking-transparency-panel')

    {{-- ===== B6: scheduled auto-sync + manual sync — only ever
         rendered while OB is enabled; hidden entirely when off, per
         UI-SPEC's Copywriting Contract ("Sync-now disabled caption (OB
         off) | (button hidden entirely)") ===== --}}
    @if ($enabled)
        <div class="space-y-2" data-testid="open-banking-sync-now">
            @if ($syncFlashMessage !== '')
                {{-- Only the zero-result flash clears itself, so those two
                     attributes cannot be written on the tag: a Blade @if
                     inside a component tag is not parsed. They ride in as a
                     bag instead. --}}
                @php
                    $syncFlashName = match ($syncFlashTone) {
                        'error' => 'danger',
                        'success' => 'positive',
                        default => 'neutral',
                    };
                    $syncFlashSelfClear = new ComponentAttributeBag($syncFlashTone === 'zero' ? [
                        'wire:init' => "\$set('syncFlashMessage', '')",
                        'wire:transition.duration.4000ms' => true,
                    ] : []);
                @endphp
                <x-core::alert
                    :tone="$syncFlashName"
                    role="{{ $syncFlashTone === 'error' ? 'alert' : 'status' }}"
                    :attributes="$syncFlashSelfClear"
                    data-testid="ob-sync-flash"
                >
                    {{ $syncFlashMessage }}
                    @if ($syncFlashTone === 'success' && $syncReviewImportRunId !== null)
                        <a
                            href="{{ route('imports.preview', ['id' => $syncReviewImportRunId]) }}"
                            class="ml-1 font-medium underline"
                            data-testid="ob-review-import-link"
                        >{{ Lang::get('openbanking::messages.sync.review_import') }} &rarr;</a>
                    @endif
                </x-core::alert>
            @endif

            <div class="flex items-center justify-between gap-3">
                @if ($consentStatus === 'expired')
                    <p class="text-xs text-slate-500 dark:text-slate-400" data-testid="ob-sync-now-disabled-caption">{{ Lang::get('openbanking::messages.sync.reconnect_first') }}</p>
                @else
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('openbanking::messages.sync.auto_caption') }}</p>
                @endif
                <x-core::neutral-button
                    class="min-h-[44px] disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="$consentStatus === 'expired'"
                    wire:click="syncNow"
                    wire:loading.attr="disabled"
                    wire:target="syncNow"
                    data-testid="ob-sync-now-button"
                >
                    <x-core::spinner size="sm" wire:loading wire:target="syncNow" class="mr-2" />
                    {{ Lang::get('openbanking::messages.sync.sync_now') }}
                </x-core::neutral-button>
            </div>
        </div>
    @endif

    {{-- ===== B7: guided ICS file-import affordance — always
         visible regardless of OB state, visually separated from the OB
         cards above by its own section label ===== --}}
    @include('openbanking::partials.open-banking-ics-import-card')

    @include('openbanking::partials.open-banking-warning-modal')

    {{-- ===== Disconnect confirm — shared by the ON->OFF toggle click and
         the B4 transparency panel's "Disconnect" button ===== --}}
    @if ($showDisconnectModal)
        <flux:modal wire:model="showDisconnectModal" class="md:max-w-sm" data-testid="open-banking-disconnect-modal">
            <div class="space-y-4 p-6">
                <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('openbanking::messages.disconnect.heading') }}</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ Lang::get('openbanking::messages.disconnect.body') }}
                </p>
                <div class="flex gap-3">
                    <button
                        type="button"
                        wire:click="disconnect"
                        wire:loading.attr="disabled"
                        class="flex-1 min-h-[44px] rounded-md bg-rose-600 px-4 py-2 text-sm font-semibold text-white
                               hover:bg-rose-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2"
                        data-testid="ob-confirm-disconnect"
                    >{{ Lang::get('openbanking::messages.disconnect.confirm') }}</button>
                    <x-core::secondary-button
                        block="flex"
                        class="min-h-[44px]"
                        wire:click="cancelDisconnect"
                        autofocus
                        data-testid="ob-cancel-disconnect"
                    >{{ Lang::get('openbanking::messages.disconnect.cancel') }}</x-core::secondary-button>
                </div>
            </div>
        </flux:modal>
    @endif

    @livewire('openbanking.open-banking-wizard-modal', key('open-banking-wizard-modal'))
</div>
