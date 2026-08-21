@use('Modules\Core\Public\Support\Lang')
@php
    use Modules\Ledger\Public\ValueObjects\Money;

    $fmt = static fn (int $minor, string $currency): string => Money::ofMinor($minor, $currency)->format();

    // Header-action enablement. Discard is meaningful whenever a preview
    // is still in cache; Confirm requires every naming step to be resolved.
    $hasLivePreview = $preview !== null && ! $previewExpired;
    $unnamedAccountCount = $hasLivePreview ? count($preview->accountsToName) : 0;
    $canConfirmImport = $hasLivePreview
        && ! $needsIcsAccountName
        && ! $needsPaypalAccountName
        && $unnamedAccountCount === 0;
@endphp

<div class="space-y-6">
    <header>
        <div class="flex items-baseline justify-between gap-4">
            <h1 class="text-2xl font-semibold text-slate-900 tracking-tight dark:text-slate-100">{{ Lang::get('import::preview.heading') }}</h1>
            <div class="flex items-center gap-3">
                <x-core::secondary-button
                    class="disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="! $hasLivePreview"
                    wire:click="discard"
                >
                    {{ Lang::get('import::preview.discard') }}
                </x-core::secondary-button>
                <button
                    type="button"
                    wire:click="confirm"
                    @disabled(! $canConfirmImport)
                    class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:hover:bg-emerald-400 dark:bg-emerald-500"
                >
                    {{ Lang::get('import::preview.confirm') }}
                </button>
            </div>
        </div>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('import::preview.subtitle') }}</p>
    </header>

    @if ($preview === null || $previewExpired)
        <x-core::alert tone="warning">
            <p>{!! Lang::get('import::preview.expired_html') !!}</p>
        </x-core::alert>
    @elseif ($needsIcsAccountName)
        <section class="space-y-4 rounded-md border border-slate-200 bg-slate-50 p-6 dark:bg-slate-900 dark:border-slate-700">
            <div class="space-y-3">
                <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ Lang::get('import::preview.ics.heading') }}</p>
                {{-- {!! !!}: app-static copy whose apostrophe must reach the DOM
                     literally (not as &#039;). No user data is interpolated. --}}
                <p class="text-sm text-slate-500 dark:text-slate-400">{!! Lang::get('import::preview.ics.help') !!}</p>
                <div class="flex items-end gap-2">
                    <div class="flex-1">
                        <x-core::form-field
                            name="icsAccountName"
                            :label="Lang::get('import::preview.account_name_label')"
                            wire:model="icsAccountName"
                            :placeholder="Lang::get('import::preview.ics.placeholder')"
                            required
                            maxlength="80"
                        />
                    </div>
                    <button
                        type="button"
                        wire:click="saveIcsAccountName"
                        class="bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-md px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:hover:bg-emerald-400 dark:bg-emerald-500"
                    >
                        {{ Lang::get('import::preview.save_name') }}
                    </button>
                </div>
            </div>
        </section>
    @elseif ($needsPaypalAccountName)
        <section class="space-y-4 rounded-md border border-slate-200 bg-slate-50 p-6 dark:bg-slate-900 dark:border-slate-700">
            <div class="space-y-3">
                <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ Lang::get('import::preview.paypal.heading') }}</p>
                {{-- {!! !!}: app-static copy whose apostrophe must reach the DOM
                     literally (not as &#039;). No user data is interpolated. --}}
                <p class="text-sm text-slate-500 dark:text-slate-400">{!! Lang::get('import::preview.paypal.help') !!}</p>
                <div class="flex items-end gap-2">
                    <div class="flex-1">
                        <x-core::form-field
                            name="paypalAccountName"
                            :label="Lang::get('import::preview.account_name_label')"
                            wire:model="paypalAccountName"
                            :placeholder="Lang::get('import::preview.paypal.placeholder')"
                            required
                            maxlength="80"
                        />
                    </div>
                    <button
                        type="button"
                        wire:click="savePaypalAccountName"
                        class="bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-md px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:hover:bg-emerald-400 dark:bg-emerald-500"
                    >
                        {{ Lang::get('import::preview.save_name') }}
                    </button>
                </div>
            </div>
        </section>
    @else
        @if (count($preview->accountsToName) > 0)
            <section class="space-y-4 rounded-md border border-slate-200 bg-slate-50 p-6 dark:bg-slate-900 dark:border-slate-700">
                @foreach ($preview->accountsToName as $unknown)
                    <div class="space-y-3">
                        <p class="text-sm text-slate-900 dark:text-slate-100">
                            {{ Lang::get('import::preview.unknown_iban_prefix') }} <strong class="font-medium">{{ $unknown->iban }}</strong>. {{ Lang::get('import::preview.unknown_iban_suffix') }}
                        </p>
                        <div class="flex items-end gap-2">
                            <div class="flex-1">
                                <x-core::form-field
                                    name="accountName"
                                    :label="Lang::get('import::preview.account_name_label')"
                                    wire:model="accountName"
                                    :placeholder="Lang::get('import::preview.account_placeholder')"
                                    required
                                    maxlength="80"
                                />
                            </div>
                            <button
                                type="button"
                                wire:click="nameAccount(@js($unknown->iban), $wire.accountName)"
                                class="bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-md px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:hover:bg-emerald-400 dark:bg-emerald-500"
                            >
                                {{ Lang::get('import::preview.save_name') }}
                            </button>
                        </div>
                    </div>
                @endforeach
            </section>
        @else
            <x-core::data-table style="font-feature-settings: 'tnum';">
                <x-slot:head>
                    <x-core::th align="left">{{ Lang::get('import::preview.col_date') }}</x-core::th>
                    <x-core::th align="left">{{ Lang::get('import::preview.col_funding_source') }}</x-core::th>
                    <x-core::th align="left">{{ Lang::get('import::preview.col_counterparty') }}</x-core::th>
                    <x-core::th align="right">{{ Lang::get('import::preview.col_amount') }}</x-core::th>
                    <x-core::th align="left">{{ Lang::get('import::preview.col_status') }}</x-core::th>
                </x-slot:head>

                @foreach ($preview->rows as $row)
                    <tr>
                        <td class="px-4 py-2 text-sm text-slate-900 dark:text-slate-100">{{ $row->bookedAt ?? '—' }}</td>
                        {{-- Funding source: counterparty IBAN that funded this row,
                             rendered monospace so it lines up legibly with the
                             IBAN fallback in the Counterparty column. Empty when
                             the source row carries no counterparty IBAN (bank fees,
                             interest credits, ATM withdrawals). --}}
                        <td class="px-4 py-2 text-sm text-slate-700 dark:text-slate-300">
                            @if ($row->counterpartyIban !== null)
                                <span class="font-mono text-xs">{{ $row->counterpartyIban }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-2 text-sm text-slate-900 dark:text-slate-100">
                            {{-- Fallback chain: aliasFriendlyName → counterpartyName →
                                 counterpartyIBAN → joined description (italic, click-to-rename)
                                 → "—". The italic .desc-fallback span is the click target
                                 for the rename popover; the popover is mounted once below
                                 the table and listens for `rename-counterparty:open`. --}}
                            @if ($row->aliasFriendlyName !== null)
                                {{ $row->aliasFriendlyName }}
                            @elseif ($row->counterpartyName !== null)
                                {{ $row->counterpartyName }}
                            @elseif ($row->counterpartyIban !== null)
                                <span class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ $row->counterpartyIban }}</span>
                            @elseif ($row->description !== null)
                                <button
                                    type="button"
                                    class="desc-fallback"
                                    aria-label="{{ Lang::get('import::preview.rename_aria') }}"
                                    wire:click="$dispatch('rename-counterparty:open', { raw: @js($row->description), rowIndex: {{ $row->rowIndex }} })"
                                >{{ $row->description }}</button>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right text-sm text-slate-900 dark:text-slate-100">
                            @if ($row->amountMinor !== null && $row->currency !== null)
                                {{ $fmt($row->amountMinor, $row->currency) }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-2 text-sm">
                            @if ($row->status === 'new')
                                <span class="inline-flex items-center rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20 dark:text-emerald-400" title="{{ Lang::get('import::preview.status.new_title') }}">{{ Lang::get('import::preview.status.new') }}</span>
                            @elseif ($row->status === 'duplicate')
                                <span class="inline-flex items-center rounded-md bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-950" title="{{ Lang::get('import::preview.status.duplicate_title') }}">{{ Lang::get('import::preview.status.duplicate') }}</span>
                            @elseif ($row->status === 'enriched')
                                <span class="inline-flex items-center rounded-md bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700 ring-1 ring-inset ring-sky-600/20" title="{{ Lang::get('import::preview.status.enriched_title') }}">{{ Lang::get('import::preview.status.enriched') }}</span>
                                @if ($row->diff && isset($row->diff['source_ref']))
                                    <div class="mt-1 text-xs text-slate-500 font-mono dark:text-slate-400">
                                        source_ref:
                                        <span class="text-slate-400">{{ $row->diff['source_ref']['from'] ?? '∅' }}</span>
                                        →
                                        <span class="text-sky-700">{{ $row->diff['source_ref']['to'] }}</span>
                                    </div>
                                @endif
                            @else
                                <span class="inline-flex items-center rounded-md bg-rose-50 px-2 py-0.5 text-xs font-medium text-rose-700 ring-1 ring-inset ring-rose-600/20 dark:bg-rose-950 dark:text-rose-500" title="{{ $row->error }}">{{ Lang::get('import::preview.status.error') }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-core::data-table>
        @endif
    @endif

    {{-- Chain-resolution polling surface (D-103 / D-105).

         Polls the `chain_resolution_runs` audit table via
         `wire:poll.2s="refreshChainResolutionStatus"`. The auto-navigate
         on status='complete' fires inside `refreshChainResolutionStatus`
         itself; the rendered Blade body covers pending / running /
         failed states only.

         Issue #1 + #8 lock: the polling target queries
         `chain_resolution_runs` by exact user_id — NEVER
         `failed_jobs.payload LIKE '%userId:N%'` (which leaks
         cross-user state via id-prefix substring matches). --}}
    @if ($chainResolutionStatus !== null && $chainResolutionStatus !== 'complete')
        <section
            wire:poll.2s="refreshChainResolutionStatus"
            class="rounded-md border border-slate-200 bg-white p-6 dark:bg-slate-950 dark:border-slate-700"
            aria-live="polite"
        >
            <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('import::preview.chain.heading') }}</h3>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                @if ($chainResolutionStatus === 'pending')
                    {{ Lang::get('import::preview.chain.pending') }}
                @elseif ($chainResolutionStatus === 'running')
                    {{ Lang::get('import::preview.chain.running') }}
                @elseif ($chainResolutionStatus === 'failed')
                    {{ Lang::get('import::preview.chain.failed_prefix') }} {{ $chainResolutionError ?? Lang::get('import::preview.chain.unknown_error') }}.
                    <a href="/horizon/failed" class="font-medium text-slate-900 underline underline-offset-2 hover:text-slate-700 dark:hover:text-slate-300 dark:text-slate-100">{{ Lang::get('import::preview.chain.open_horizon') }}</a>
                    {{ Lang::get('import::preview.chain.failed_suffix') }}
                @endif
            </p>
            @if ($chainResolutionStatus !== 'failed')
                <span aria-hidden="true" class="mt-3 inline-block h-2 w-2 animate-pulse rounded-full bg-slate-400"></span>
            @endif
        </section>
    @endif

    {{-- Rename counterparty popover. Single mount per wizard page; the
         italic .desc-fallback spans in the Counterparty column above
         dispatch `rename-counterparty:open` with the raw description
         + row index so this component can open a Flux modal anchored
         to that row. Saving the popover writes the merchant_aliases
         row + optional categorization_rules row, then dispatches
         `rename-counterparty:saved` for the parent wizard to update
         the affected aliasFriendlyName in place. --}}
    <livewire:import.rename-counterparty-popover />
</div>
