@use('Modules\Core\Public\Enums\JobRunStatus')
@use('Modules\Core\Public\Support\Iban')
@use('Modules\Core\Public\Support\Lang')
@php
    use Modules\Core\Public\Support\Fmt;
    use Modules\Import\Public\Enums\ImportFailureReason;
    use Modules\Import\Public\Enums\PreviewRowStatus;
    use Modules\Ledger\Public\ValueObjects\Money;

    $fmt = static fn (int $minor, string $currency): string => Money::ofMinor($minor, $currency)->format();

    // Header-action enablement. Discard is meaningful whenever a preview
    // is still in cache; Confirm requires every naming step to be resolved
    // AND at least one row that confirming would actually write.
    $hasLivePreview = $preview !== null && ! $previewExpired;
    $unnamedAccountCount = $hasLivePreview ? count($preview->accountsToName) : 0;
    $canConfirmImport = $hasLivePreview
        && ! $needsIcsAccountName
        && ! $needsPaypalAccountName
        && $unnamedAccountCount === 0
        && $importableRowCount > 0;

    // A file-level failure is not a row and has no cells of its own. It says
    // where the read stopped, which is the only thing that explains the rows
    // that are missing rather than present-and-failed.
    $fileFailure = $hasLivePreview && $preview->fileFailureReason !== null;
    $fileFailureDetail = $hasLivePreview ? $preview->fileFailureDetail : null;
    $parsedRowCount = $hasLivePreview ? $preview->totalRows() : 0;
    $shownRowCount = $hasLivePreview ? count($preview->rows) : 0;
    $nothingToImport = $hasLivePreview && $importableRowCount === 0;
@endphp

<div class="space-y-6">
    <header>
        {{-- `shrink-0` and `flex-wrap` together, or the pair squeezes: the row
             gave the two buttons 84.1px each and "Confirm" needs 84.3px, so it
             broke mid-word. Now the group keeps its own width and drops below
             the heading when the row cannot hold both. --}}
        <div class="flex flex-wrap items-baseline justify-between gap-4">
            <x-core::page-heading>{{ Lang::get('import::preview.heading') }}</x-core::page-heading>
            <div class="flex shrink-0 items-center gap-3">
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
                    class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:hover:bg-emerald-800 dark:bg-emerald-700"
                >
                    {{ Lang::get('import::preview.confirm') }}
                </button>
            </div>
        </div>
        @unless ($nothingToImport)
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('import::preview.subtitle') }}</p>
        @endunless
    </header>

    @if ($alreadyImported)
        <x-core::alert tone="info">
            <p>{{ Lang::get('import::preview.already_imported') }}</p>
            <p class="mt-2">
                <a href="{{ route('imports.results', ['id' => $importRunId]) }}" class="underline">
                    {{ Lang::get('import::preview.already_imported_link') }}
                </a>
            </p>
        </x-core::alert>
    @elseif ($preview === null || $previewExpired)
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
                {{-- Stacked below sm, here and at the two rows below it: side by
                     side on a 375px screen the input measured 173px against a
                     placeholder needing 197, and the squeezed button broke
                     "Save name" over two lines and outgrew its own row. The
                     width is the whole fix -- a nowrap here would be inert,
                     because the coarse-pointer block deliberately lets every
                     control label wrap so it cannot take the page sideways at
                     the reader's largest text sizes. --}}
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
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
                        class="bg-emerald-700 hover:bg-emerald-800 text-white font-medium rounded-md px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700 focus-visible:ring-offset-2 dark:hover:bg-emerald-800 dark:bg-emerald-700"
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
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
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
                        class="bg-emerald-700 hover:bg-emerald-800 text-white font-medium rounded-md px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700 focus-visible:ring-offset-2 dark:hover:bg-emerald-800 dark:bg-emerald-700"
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
                            {{ in_array($unknown->iban, $presetIssuedIdentifiers, true)
                                ? Lang::get('import::preview.unknown_account_prefix')
                                : Lang::get('import::preview.unknown_iban_prefix') }} <strong class="font-medium">{{ Iban::grouped($unknown->iban) }}</strong>. {{ Lang::get('import::preview.unknown_iban_suffix') }}
                        </p>
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
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
                                class="bg-emerald-700 hover:bg-emerald-800 text-white font-medium rounded-md px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700 focus-visible:ring-offset-2 dark:hover:bg-emerald-800 dark:bg-emerald-700"
                            >
                                {{ Lang::get('import::preview.save_name') }}
                            </button>
                        </div>
                    </div>
                @endforeach
            </section>
        @else
            @if ($nothingToImport)
                <x-core::alert tone="danger" role="alert">
                    <p class="font-medium">{{ Lang::get('import::preview.failed.heading') }}</p>
                    <p class="mt-2">
                        @if ($parsedRowCount === 0 && ! $fileFailure)
                            {{ Lang::get('import::preview.failed.no_rows') }}
                        @elseif ($parsedRowCount === 0)
                            {{ Lang::get('import::preview.failed.nothing_read') }}
                        @else
                            {{ Lang::get('import::preview.failed.every_row') }}
                        @endif
                    </p>
                    @if ($fileFailure)
                        <p class="mt-2">{{ $preview->fileFailureReason->fileCause() }}</p>
                    @endif
                    @if ($fileFailureDetail !== null)
                        <p class="mt-2">
                            <span class="font-medium">{{ Lang::get('import::preview.failed.detail_label') }}</span>
                            <span class="font-mono text-xs">{{ $fileFailureDetail }}</span>
                        </p>
                    @endif
                </x-core::alert>
            @elseif ($fileFailure)
                <x-core::alert tone="warning" role="alert">
                    <p class="font-medium">{{ Lang::get('import::preview.failed.truncated_heading') }}</p>
                    <p class="mt-2">{{ Lang::get('import::preview.failed.truncated') }}</p>
                    <p class="mt-2">
                        {{ Lang::get('import::preview.failed.rows_read_label') }}: {{ $importableRowCount }}
                    </p>
                    @if ($fileFailureDetail !== null)
                        <p class="mt-2">
                            <span class="font-medium">{{ Lang::get('import::preview.failed.detail_label') }}</span>
                            <span class="font-mono text-xs">{{ $fileFailureDetail }}</span>
                        </p>
                    @endif
                </x-core::alert>
            @elseif ($failedRowCount > 0)
                <x-core::alert tone="warning" role="alert">
                    <p>{{ Lang::get('import::preview.failed.some_rows') }}</p>
                    <p class="mt-2">
                        {{ Lang::get('import::preview.failed.rows_read_label') }}: {{ $importableRowCount }}
                        · {{ Lang::get('import::preview.failed.rows_skipped_label') }}: {{ $failedRowCount }}
                    </p>
                </x-core::alert>
            @endif

        @endif

        {{-- Outside the naming branch, so a first import is not a form over a
             blank page. Every row is an unnamed-account error in that state, but
             it is the reader's own file: they can see the dates, the amounts and
             the counterparties, and tell whether they picked the right export. --}}
                @if ($parsedRowCount > 0)
                <x-core::data-table class="preview-rows-table" style="font-feature-settings: 'tnum';">
                    <x-slot:head>
                        <x-core::th align="left">{{ Lang::get('import::preview.col_date') }}</x-core::th>
                        <x-core::th align="left">{{ Lang::get('import::preview.col_funding_source') }}</x-core::th>
                        <x-core::th align="left">{{ Lang::get('import::preview.col_counterparty') }}</x-core::th>
                        <x-core::th align="right">{{ Lang::get('import::preview.col_amount') }}</x-core::th>
                        <x-core::th align="left">{{ Lang::get('import::preview.col_status') }}</x-core::th>
                    </x-slot:head>

                    @foreach ($preview->rows as $row)
                        <tr data-row-index="{{ $row->rowIndex }}">
                            <td class="px-4 py-2 text-sm text-slate-900 dark:text-slate-100">{{ $row->bookedAt ?? '—' }}</td>
                            {{-- Funding source: counterparty IBAN that funded this row,
                                 rendered monospace so it lines up legibly with the
                                 IBAN fallback in the Counterparty column. Empty when
                                 the source row carries no counterparty IBAN (bank fees,
                                 interest credits, ATM withdrawals). --}}
                            <td class="px-4 py-2 text-sm text-slate-700 dark:text-slate-300">
                                @if ($row->counterpartyIban !== null)
                                    <span class="font-mono text-xs">{{ Iban::grouped($row->counterpartyIban) }}</span>
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
                                    <span class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ Iban::grouped($row->counterpartyIban) }}</span>
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
                                {{-- A row the reader has to go and find in their own file needs the
                                     text their file actually carries. The fallback chain above shows
                                     whichever identifier came out highest, so on a failed row the raw
                                     description is added rather than hidden behind a resolved name. --}}
                                @if ($row->status === PreviewRowStatus::Error && $row->description !== null)
                                    <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $row->description }}</div>
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
                                @if ($row->status === PreviewRowStatus::NewRow)
                                    <span class="inline-flex items-center rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20 dark:text-emerald-400" title="{{ Lang::get('import::preview.status.new_title') }}">{{ Lang::get('import::preview.status.new') }}</span>
                                @elseif ($row->status === PreviewRowStatus::Duplicate)
                                    <span class="inline-flex items-center rounded-md bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-950" title="{{ Lang::get('import::preview.status.duplicate_title') }}">{{ Lang::get('import::preview.status.duplicate') }}</span>
                                @elseif ($row->status === PreviewRowStatus::Enriched)
                                    <span class="inline-flex items-center rounded-md bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700 ring-1 ring-inset ring-sky-600/20" title="{{ Lang::get('import::preview.status.enriched_title') }}">{{ Lang::get('import::preview.status.enriched') }}</span>
                                    @if ($row->diff && isset($row->diff['source_ref']))
                                        <div class="mt-1 text-xs text-slate-500 font-mono dark:text-slate-400">
                                            source_ref:
                                            <span class="text-slate-600 dark:text-slate-400">{{ $row->diff['source_ref']['from'] ?? '∅' }}</span>
                                            →
                                            <span class="text-sky-700">{{ $row->diff['source_ref']['to'] }}</span>
                                        </div>
                                    @endif
                                @else
                                    {{-- The reason, spelled out under the badge rather than hidden in a
                                         title attribute. There is no hover on a phone, so the tooltip was
                                         unreachable on the device this screen is mostly used from — and it
                                         carried the exception's own words, which name internal classes and
                                         the reader's user id. --}}
                                    <span class="inline-flex items-center rounded-md bg-rose-50 px-2 py-0.5 text-xs font-medium text-rose-700 ring-1 ring-inset ring-rose-600/20 dark:bg-rose-950 dark:text-rose-500">{{ Lang::get('import::preview.status.error') }}</span>
                                    <div class="mt-1 text-xs text-rose-700 dark:text-rose-400">
                                        {{ ImportFailureReason::labelFor($row->errorReason) }}
                                    </div>
                                    @if ($row->errorDetail !== null)
                                        <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $row->errorDetail }}</div>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-core::data-table>

                {{-- Never a silent cap: the table draws a window, and the count
                     beside it is the run's, so a reader who imported 27,777 rows
                     is not left believing the hundred on screen are all of them. --}}
                @if ($shownRowCount < $parsedRowCount)
                    <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                        <p class="text-sm text-slate-500 dark:text-slate-400" aria-live="polite">
                            {{ Lang::get('import::preview.rows_shown', ['shown' => Fmt::number($shownRowCount), 'total' => Fmt::number($parsedRowCount)]) }}
                        </p>
                        <x-core::secondary-button type="button" wire:click="showMoreRows" wire:loading.attr="disabled">
                            {{ Lang::get('import::preview.show_more') }}
                        </x-core::secondary-button>
                    </div>
                @endif
                @endif
    @endif

    {{-- Chain-resolution polling surface.

         Polls the `chain_resolution_runs` audit table via
         `wire:poll.2s.keep-alive="refreshChainResolutionStatus"`. The auto-navigate
         on status='complete' fires inside `refreshChainResolutionStatus`
         itself; the rendered Blade body covers pending / running /
         failed states only.

         The polling target queries
         `chain_resolution_runs` by exact user_id — NEVER
         `failed_jobs.payload LIKE '%userId:N%'` (which leaks
         cross-user state via id-prefix substring matches). --}}
    @if ($chainResolutionStatus !== null && $chainResolutionStatus !== JobRunStatus::Complete)
        <section
            wire:poll.2s.keep-alive="refreshChainResolutionStatus"
            class="rounded-md border border-slate-200 bg-white p-6 dark:bg-slate-950 dark:border-slate-700"
            aria-live="polite"
        >
            <x-core::section-heading :title="Lang::get('import::preview.chain.heading')" :level="3" />
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                @if ($chainResolutionStatus === JobRunStatus::Pending)
                    {{ Lang::get('import::preview.chain.pending') }}
                @elseif ($chainResolutionStatus === JobRunStatus::Running)
                    {{ Lang::get('import::preview.chain.running') }}
                @elseif ($chainResolutionStatus === JobRunStatus::Failed)
                    {{-- The stored last_error is the failed job's class name and the first
                         line of its message. That is a developer's sentence, and the crypto
                         layer's version of it names an internal class and the reader's own
                         user id. Horizon is one line down for whoever needs it. --}}
                    {{ Lang::get('import::preview.chain.failed_prefix') }} {{ Lang::get('import::preview.chain.failed_detail') }}.
                    <a href="/horizon/failed" class="font-medium text-slate-900 underline underline-offset-2 hover:text-slate-700 dark:hover:text-slate-300 dark:text-slate-100">{{ Lang::get('import::preview.chain.open_horizon') }}</a>
                    {{ Lang::get('import::preview.chain.failed_suffix') }}
                @endif
            </p>
            @if ($chainResolutionStatus !== JobRunStatus::Failed)
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
