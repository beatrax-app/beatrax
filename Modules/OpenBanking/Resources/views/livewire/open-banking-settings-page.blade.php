@use('Modules\Core\Public\Navigation\Destination')
@use('Illuminate\View\ComponentAttributeBag')
@use('Modules\Core\Public\Support\Lang')
@use('Modules\OpenBanking\Internal\Enums\ConsentStatus')

<div class="max-w-2xl mx-auto space-y-6" data-testid="open-banking-settings-page">
    <a href="{{ Destination::Settings->url() }}" class="tap-link text-sm text-slate-500 hover:underline dark:text-slate-400">&larr; {{ Lang::get('openbanking::messages.page.back_link') }}</a>

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

    {{-- The toggle below reads "off" whenever the credentials cannot be read,
         which on its own would say the reader turned it off. --}}
    @if ($credentialsUnreadable)
        <x-core::alert tone="danger" role="alert" data-testid="ob-credentials-unreadable">
            <p>{{ Lang::get('openbanking::messages.page.credentials_unreadable') }}</p>
            <p class="mt-2">{{ Lang::get('openbanking::messages.page.credentials_unreadable_next') }}</p>
        </x-core::alert>
    @endif

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

    {{-- The SCA dance completed but the acknowledgement TTL lapsed before
         mount() could finalize the enable, which would otherwise be a silent
         no-op the reader has no way to understand or undo. --}}
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

    {{-- Above the transparency panel, not inside it: an expired consent is a
         thing to act on, and the panel below is a thing to read. --}}
    @include('openbanking::partials.open-banking-consent-banner')

    @include('openbanking::partials.open-banking-transparency-panel')

    {{-- Hidden entirely while open banking is off, rather than shown disabled:
         there is nothing to sync and nothing for a caption to explain. --}}
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
                    {{-- Keyed on the id alone. Gated on the success tone as
                         well, the link was unreachable from the two flashes
                         that most need it: a truncated walk and a run that
                         filed none of its rows. --}}
                    @if ($syncReviewImportRunId !== null)
                        <a
                            href="{{ route('imports.preview', ['id' => $syncReviewImportRunId]) }}"
                            class="ml-1 font-medium underline"
                            data-testid="ob-review-import-link"
                        >{{ Lang::get('openbanking::messages.sync.review_import') }} &rarr;</a>
                    @endif
                </x-core::alert>
            @endif

            @php($needsReconnect = ConsentStatus::from($consentStatus)->needsReconnect())
            <div class="flex items-center justify-between gap-3">
                @if ($needsReconnect)
                    <p class="text-xs text-slate-500 dark:text-slate-400" data-testid="ob-sync-now-disabled-caption">{{ Lang::get('openbanking::messages.sync.reconnect_first') }}</p>
                @else
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('openbanking::messages.sync.auto_caption') }}</p>
                @endif
                <x-core::neutral-button
                    class="min-h-[44px] disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="$needsReconnect"
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

    {{-- Visible regardless of open-banking state — it stores no credentials
         and needs no consent — so it carries its own section label to keep it
         from reading as part of the connector above. --}}
    @include('openbanking::partials.open-banking-ics-import-card')

    @include('openbanking::partials.open-banking-warning-modal')

    {{-- One confirm for both entry points: the on-to-off toggle click and the
         transparency panel's own Disconnect button. --}}
    @if ($showDisconnectModal)
        <flux:modal wire:model="showDisconnectModal" class="md:max-w-sm" data-testid="open-banking-disconnect-modal">
            <div class="space-y-4 p-6">
                <x-core::section-heading :title="Lang::get('openbanking::messages.disconnect.heading')" :level="3" />
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
