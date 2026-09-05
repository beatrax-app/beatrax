@use('Illuminate\View\ComponentAttributeBag')
@use('Modules\Core\Public\Support\Lang')
@use('Modules\OpenBanking\Internal\Enums\ConsentStatus')

<div class="space-y-2" data-testid="open-banking-connection-card" wire:key="ob-card-{{ $connectionId }}">
    {{-- Above the transparency panel, not inside it: an expired consent is a
         thing to act on, and the panel below is a thing to read. --}}
    @include('openbanking::partials.open-banking-consent-banner')

    @include('openbanking::partials.open-banking-transparency-panel')

    {{-- Hidden entirely while this bank is off, rather than shown disabled:
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
</div>
