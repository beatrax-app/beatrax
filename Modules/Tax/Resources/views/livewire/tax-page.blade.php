@use('Modules\Core\Public\Navigation\Destination')
@use('Modules\Core\Public\Support\Lang')
{{--
    /tax year cockpit.

    $data:           TaxYearData|null  — grouped categories + totals for the selected year
    $availableYears: array<int>        — years with tags (for year switcher options)
    $hasCountry:  bool              — false = first-visit guided empty state
    $documentTitle: string             — what the browser tab and OS window are named

    Page structure (UI-SPEC Section 8):
      [Page header: H1 + year switcher + export buttons]
      [Year-totals strip: deductions | income | item count]
      [Category sections (one per category + "No category" last)]
      [Empty states (Section 9)]

    Phone responsive: card-per-row inside sections; stacked export buttons (Section 17).
    Accessibility: aria-expanded, aria-busy, aria-label on all icon-only buttons (Section 16).
--}}
@use('Modules\Ledger\Public\ValueObjects\Money')

<div class="py-6">
    {{-- The layout writes <title> once, on the full page load; switching the
         year in place is a Livewire update, so the browser tab and the desktop
         app's OS window went on naming the year the reader had just left. The
         wire:key carries the year, so the morph replaces this node and Alpine
         initialises the new one. --}}
    <div wire:key="tax-document-title-{{ $year }}" x-data x-init="document.title = @js($documentTitle)" hidden></div>

    <div class="mx-auto max-w-4xl px-4 sm:px-6">

        {{-- ────────────────────────────────────────────────────────────────── --}}
        {{-- Page header                                                        --}}
        {{-- ────────────────────────────────────────────────────────────────── --}}

        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <x-core::page-heading style="color: var(--color-text);">
                {{ Lang::get('tax::page.title', ['year' => $year]) }}
            </x-core::page-heading>

            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                {{-- Year switcher — wire:model.live updates results immediately --}}
                @if (count($availableYears) > 0)
                    <div class="flex flex-wrap gap-1" role="group" aria-label="{{ Lang::get('tax::page.select_year_aria') }}">
                        @php
                            // Render ALL available years — history is retained forever
                            // (project constraint); the container flex-wraps past one
                            // row, so a hard cap would silently hide older years.
                            $years = array_unique(array_merge([$year], $availableYears));
                            rsort($years);
                        @endphp
                        @foreach ($years as $y)
                            <button
                                wire:click="$set('year', {{ $y }})"
                                class="rounded-md px-3 py-1 text-sm font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 {{ $y === $year ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'border border-slate-200 bg-white text-slate-500 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400 dark:hover:bg-slate-800' }}"
                                aria-pressed="{{ $y === $year ? 'true' : 'false' }}"
                                aria-label="{{ Lang::get('tax::page.show_year_aria', ['year' => $y]) }}"
                            >{{ $y }}</button>
                        @endforeach
                    </div>
                @endif

                {{-- Export buttons — stream-download, with aria-busy while in flight --}}
                <div class="flex gap-2 sm:ml-2">
                    <button
                        wire:click="exportCsv"
                        wire:loading.attr="aria-busy"
                        wire:loading.attr="disabled"
                        wire:target="exportCsv"
                        class="tax-export-btn"
                        aria-label="{{ Lang::get('tax::page.export_csv_aria') }}"
                        title="{{ Lang::get('tax::page.export_csv_title', ['year' => $year]) }}"
                    >
                        <span aria-hidden="true" wire:loading.remove wire:target="exportCsv">↓</span>
                        <span aria-hidden="true" wire:loading wire:target="exportCsv">…</span>
                        {{ Lang::get('tax::page.export_csv') }}
                    </button>
                    <button
                        wire:click="exportPdf"
                        wire:loading.attr="aria-busy"
                        wire:loading.attr="disabled"
                        wire:target="exportPdf"
                        class="tax-export-btn"
                        aria-label="{{ Lang::get('tax::page.export_pdf_aria') }}"
                        title="{{ Lang::get('tax::page.export_pdf_title', ['year' => $year]) }}"
                    >
                        <span aria-hidden="true" wire:loading.remove wire:target="exportPdf">↓</span>
                        <span aria-hidden="true" wire:loading wire:target="exportPdf">…</span>
                        {{ Lang::get('tax::page.export_pdf') }}
                    </button>
                </div>
            </div>
        </div>

        {{-- Where the shell drops what the WebView downloads, the export leaves
             through the OS share sheet and this line is the only thing on the
             page that changes. It said nothing at all before. --}}
        @if ($flashMessage !== '')
            <x-core::alert tone="positive" class="px-4 py-2" aria-atomic="true" aria-live="polite">
                {{ $flashMessage }}
                <button type="button" wire:click="clearFlash" class="ml-3 text-xs underline">{{ Lang::get('tax::page.export_dismiss') }}</button>
            </x-core::alert>
        @endif

        {{-- ────────────────────────────────────────────────────────────────── --}}
        {{-- First-visit empty state (no tax country set)                       --}}
        {{-- UI-SPEC Section 9 — country not yet set                            --}}
        {{-- ────────────────────────────────────────────────────────────────── --}}

        {{-- A prompt, not a wall.

             This used to replace the entire page, so a user whose dashboard
             card read "BELASTING 2026 · € 1.237,89 · 18 items getagd" tapped
             through and was told only that the app did not know which country
             they file in. Two screens contradicting each other about money is
             worse than either being incomplete.

             The country is not a precondition for any of it: TaxPage builds
             $data from the tagged items before it ever looks the country up.
             So the prompt sits above the figures it refines, and the figures
             stay on screen. --}}
        @if (! $hasCountry)
            {{-- A tinted band, not a second .card: stacked directly on the
                 totals strip it repeated the same white surface, border and
                 shadow, so the two read as one card drawn twice. --}}
            <div class="mb-6 rounded-lg p-4" style="background: var(--color-surface-2);">
                <p style="font-size: var(--text-base); font-weight: 600; color: var(--color-text); margin: 0 0 var(--space-1);">
                    {{ Lang::get('tax::page.country_prompt_heading') }}
                </p>
                <p style="font-size: var(--text-sm); color: var(--color-text-muted); margin: 0 0 var(--space-3);">
                    {{ Lang::get('tax::page.country_prompt_body', ['section' => Lang::get('core::settings.country.heading')]) }}
                </p>
                {{-- The country moved out of the Tax section and in beside the
                     language, so both the anchor and the word in the sentence
                     name the control that still exists. --}}
                <a
                    href="{{ Destination::Settings->url() }}#country"
                    class="pill-btn-primary"
                    style="display: inline-block; text-decoration: none;"
                >{{ Lang::get('tax::page.country_prompt_cta') }}</a>
            </div>
        @endif

        @if ($data !== null)

        {{-- ────────────────────────────────────────────────────────────────── --}}
        {{-- Year-totals strip                                                  --}}
        {{-- ────────────────────────────────────────────────────────────────── --}}

            <div class="tax-totals-strip mb-6">
                <div class="flex flex-col">
                    <span style="font-size: var(--text-xs); color: var(--color-text-faint); text-transform: uppercase; letter-spacing: 0.06em; font-weight: 600;">{{ Lang::get('tax::page.total_deductions') }}</span>
                    <span class="kpi-number" style="font-size: var(--text-xl); font-weight: 600; color: var(--color-text);">
                        {{ Money::ofMinor($data->deductionsTotalMinor, $data->currency)->format() }}
                    </span>
                </div>

                @if ($data->incomeTotalMinor > 0)
                    <div class="flex flex-col">
                        <span style="font-size: var(--text-xs); color: var(--color-text-faint); text-transform: uppercase; letter-spacing: 0.06em; font-weight: 600;">{{ Lang::get('tax::page.income') }}</span>
                        <span class="kpi-number" style="font-size: var(--text-xl); font-weight: 600; color: var(--color-emerald);">
                            {{ Money::ofMinor($data->incomeTotalMinor, $data->currency)->format() }}
                        </span>
                    </div>
                @endif

                <div class="flex flex-col">
                    <span style="font-size: var(--text-xs); color: var(--color-text-faint); text-transform: uppercase; letter-spacing: 0.06em; font-weight: 600;">{{ Lang::get('tax::page.tagged_items') }}</span>
                    <span class="kpi-number" style="font-size: var(--text-xl); font-weight: 600; color: var(--color-text-muted);">
                        {{ $data->itemCount }}
                    </span>
                </div>

                @if ($data->isPartial())
                    <div class="flex flex-col" data-not-converted="true">
                        <span style="font-size: var(--text-xs); color: var(--color-text-faint);">{{ Lang::get('core::money.not_converted', ['list' => $data->unconvertedList()]) }}</span>
                    </div>
                @endif
            </div>

            {{-- ──────────────────────────────────────────────────────────────── --}}
            {{-- Empty-year state (UI-SPEC Section 9 — empty year view)          --}}
            {{-- ──────────────────────────────────────────────────────────────── --}}

            @if ($data->itemCount === 0)
                <div class="py-12 text-center">
                    <p style="font-size: var(--text-base); color: var(--color-text-muted);">
                        {{ Lang::get('tax::page.empty_year', ['year' => $year]) }}
                    </p>
                    @php
                        $otherYearsCount = array_sum(
                            array_map(
                                static fn (int $y): int => $y !== $year ? 1 : 0,
                                $availableYears,
                            )
                        );
                    @endphp
                    @if ($otherYearsCount > 0)
                        <p style="font-size: var(--text-base); color: var(--color-text-faint); margin-top: var(--space-2);">
                            {{ Lang::get('tax::page.other_years') }}
                        </p>
                    @else
                        <p style="font-size: var(--text-base); color: var(--color-text-faint); margin-top: var(--space-2);">
                            {{ Lang::get('tax::page.start_hint') }}
                        </p>
                    @endif
                    <a
                        href="{{ Destination::Transactions->url() }}"
                        class="tap-link font-medium underline-offset-2 hover:underline"
                        style="display: inline-block; margin-top: var(--space-4); font-size: var(--text-base); color: var(--color-text);"
                    >{{ Lang::get('tax::page.go_to_transactions') }}</a>
                </div>

            @else

                {{-- ──────────────────────────────────────────────────────────── --}}
                {{-- Category sections — one per category + "No cat" last --}}
                {{-- ──────────────────────────────────────────────────────────── --}}

                <div class="space-y-4">
                    @foreach ($data->categories as $category)
                        @php
                            $catId    = $category['id'];
                            $catName  = $category['name'] ?? null;
                            $rows     = $category['rows'] ?? [];
                            $subtotal = $category['subtotalMinor'] ?? 0;
                            $incomeSubtotal = $category['incomeSubtotalMinor'] ?? 0;
                            $count    = count($rows);
                            $isNoCategory = $catId === null;
                            $sectionKey = $isNoCategory ? 'no-cat' : 'cat-' . $catId;
                        @endphp

                        <details
                            class="tax-section"
                            @if (! $isNoCategory) open @endif
                        >
                            {{-- Section header (UI-SPEC §8 — category name + count + subtotal + chevron) --}}
                            <summary
                                class="tax-section-header cursor-pointer list-none focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
                                aria-controls="tax-section-body-{{ $sectionKey }}"
                            >
                                <div class="flex min-w-0 grow basis-auto items-center gap-3">
                                    <span style="font-size: var(--text-base); font-weight: 600; color: {{ $isNoCategory ? 'var(--color-text-faint)' : 'var(--color-text)' }};">
                                        {{ $isNoCategory ? Lang::get('tax::page.no_category') : $catName }}
                                    </span>
                                    <span
                                        role="img"
                                        style="display: inline-flex; align-items: center; padding: 1px 8px; border-radius: var(--radius-full); background: var(--color-surface); border: 1px solid var(--color-border); font-size: var(--text-xs); color: var(--color-text-muted);"
                                        aria-label="{{ Lang::choice('tax::page.items_count_aria', $count) }}"
                                    >{{ $count }}</span>
                                </div>
                                {{-- Two figures, never one: the strip above is headed
                                     "Total deductions", so a tagged income row filed
                                     under this category is counted beside the subtotal
                                     and never inside it. --}}
                                @if ($incomeSubtotal > 0)
                                    <span class="kpi-number" style="font-size: var(--text-xs); font-weight: 600; color: var(--color-emerald); margin-right: var(--space-3);">
                                        <span style="text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-text-faint);">{{ Lang::get('tax::page.income') }}</span>
                                        {{ Money::ofMinor($incomeSubtotal, $data->currency)->format() }}
                                    </span>
                                @endif
                                <span class="kpi-number" style="font-size: var(--text-base); font-weight: 600; color: var(--color-text); margin-right: var(--space-3);">
                                    {{ Money::ofMinor($subtotal, $data->currency)->format() }}
                                </span>
                                {{-- Chevron (CSS rotates when open) --}}
                                <span aria-hidden="true" style="color: var(--color-text-faint); font-size: var(--text-xs);">▾</span>
                            </summary>

                            {{-- Transaction table body --}}
                            <div id="tax-section-body-{{ $sectionKey }}">
                                {{-- Desktop: full table --}}
                                <table
                                    class="hidden w-full sm:table"
                                    style="border-collapse: collapse; font-size: var(--text-base);"
                                >
                                    <thead>
                                        {{-- No fill of its own: .tax-section-header directly above is
                                             already surface-2, so painting this row the same colour put
                                             two identical grey bars 1px apart. --}}
                                        <tr style="border-bottom: 1px solid var(--color-border);">
                                            <th class="date px-3 py-2 text-left" style="font-size: var(--text-xs); font-weight: 600; color: var(--color-text-faint); text-transform: uppercase; letter-spacing: 0.04em; width: 7rem;">{{ Lang::get('tax::page.col_date') }}</th>
                                            <th class="px-3 py-2 text-left" style="font-size: var(--text-xs); font-weight: 600; color: var(--color-text-faint); text-transform: uppercase; letter-spacing: 0.04em; width: 6rem;">{{ Lang::get('tax::page.col_account') }}</th>
                                            <th class="px-3 py-2 text-left" style="font-size: var(--text-xs); font-weight: 600; color: var(--color-text-faint); text-transform: uppercase; letter-spacing: 0.04em;">{{ Lang::get('tax::page.col_counterparty') }}</th>
                                            <th class="px-3 py-2 text-left" style="font-size: var(--text-xs); font-weight: 600; color: var(--color-text-faint); text-transform: uppercase; letter-spacing: 0.04em;">{{ Lang::get('tax::page.col_note') }}</th>
                                            <th class="money px-3 py-2 text-right" style="font-size: var(--text-xs); font-weight: 600; color: var(--color-text-faint); text-transform: uppercase; letter-spacing: 0.04em; width: 7rem;">{{ Lang::get('tax::page.col_settled') }}</th>
                                            <th class="px-3 py-2 text-right" style="font-size: var(--text-xs); font-weight: 600; color: var(--color-text-faint); text-transform: uppercase; letter-spacing: 0.04em; width: 5rem;">{{ Lang::get('tax::page.col_original') }}</th>
                                            <th class="px-3 py-2 text-center" style="font-size: var(--text-xs); font-weight: 600; color: var(--color-text-faint); text-transform: uppercase; letter-spacing: 0.04em; width: 3rem;">{{ Lang::get('tax::page.col_year') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($rows as $row)
                                            @php
                                                $postedAt  = isset($row['postedAt']) ? \Carbon\CarbonImmutable::parse($row['postedAt']) : null;
                                                $hasOverride = isset($row['taxYearOverride']) && $row['taxYearOverride'] !== null;
                                                $settledMinor = is_int($row['settledAmountMinor']) ? $row['settledAmountMinor'] : 0;
                                                $origMinor    = is_int($row['amountMinor']) ? $row['amountMinor'] : 0;
                                                $settledCurrency = is_string($row['settledCurrency']) ? $row['settledCurrency'] : $data->currency;
                                                $origCurrency = is_string($row['currency']) ? $row['currency'] : $settledCurrency;
                                                $showOrig     = $origCurrency !== $settledCurrency;
                                            @endphp
                                            <tr
                                                class="triage-row border-b"
                                                style="border-color: var(--color-border);"
                                            >
                                                {{-- Date --}}
                                                <td class="date whitespace-nowrap px-3 py-2" style="color: var(--color-text-muted);">
                                                    {{ $postedAt ? $postedAt->translatedFormat('d M Y') : '—' }}
                                                </td>
                                                {{-- Account --}}
                                                <td class="px-3 py-2 text-sm" style="color: var(--color-text-muted); max-width: 6rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                    {{ $row['accountName'] ?? '—' }}
                                                </td>
                                                {{-- Counterparty --}}
                                                <td class="px-3 py-2" style="color: var(--color-text);">
                                                    @if (! empty($row['counterpartyName']))
                                                        {{ $row['counterpartyName'] }}
                                                    @else
                                                        <span style="color: var(--color-text-faint);">—</span>
                                                    @endif
                                                </td>
                                                {{-- Note --}}
                                                <td class="px-3 py-2" style="color: var(--color-text-muted); font-style: italic; max-width: 12rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                    @if (! empty($row['note']))
                                                        {{ $row['note'] }}
                                                    @else
                                                        <span style="color: var(--color-text-faint); font-style: normal;">—</span>
                                                    @endif
                                                </td>
                                                {{-- Settled amount, in the row's own settled currency --}}
                                                <td class="money px-3 py-2 text-right" style="color: var(--color-text);">
                                                    {{ Money::ofMinor(abs($settledMinor), $settledCurrency)->format() }}
                                                </td>
                                                {{-- Original (if non-EUR) --}}
                                                <td class="px-3 py-2 text-right" style="font-size: var(--text-xs); color: var(--color-text-faint);">
                                                    @if ($showOrig)
                                                        {{ Money::ofMinor(abs($origMinor), $origCurrency)->format() }}
                                                    @else
                                                        —
                                                    @endif
                                                </td>
                                                {{-- Year / override chip --}}
                                                <td class="px-3 py-2 text-center">
                                                    @if ($hasOverride)
                                                        <span role="img" class="tax-badge--amber" aria-label="{{ Lang::get('tax::page.override_aria', ['year' => $row['taxYearOverride']]) }}">
                                                            → {{ $row['taxYearOverride'] }}
                                                        </span>
                                                    @else
                                                        <span style="color: var(--color-text-faint); font-size: var(--text-xs);">—</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>

                                {{-- Phone: card-per-row (Section 17) --}}
                                <ul class="divide-y sm:hidden" style="border-color: var(--color-border);">
                                    @foreach ($rows as $row)
                                        @php
                                            $postedAt  = isset($row['postedAt']) ? \Carbon\CarbonImmutable::parse($row['postedAt']) : null;
                                            $hasOverride = isset($row['taxYearOverride']) && $row['taxYearOverride'] !== null;
                                            $settledMinor = is_int($row['settledAmountMinor']) ? $row['settledAmountMinor'] : 0;
                                            $settledCurrency = is_string($row['settledCurrency']) ? $row['settledCurrency'] : $data->currency;
                                        @endphp
                                        <li class="px-4 py-3">
                                            <div class="flex items-start justify-between gap-2">
                                                {{-- overflow-wrap on the text, not just min-w-0 on the box:
                                                     a name with no spaces (an IBAN, a payment reference)
                                                     cannot break, so it ran out of its column and painted
                                                     straight over the amount to its right. --}}
                                                <div class="min-w-0 flex-1" style="overflow-wrap: anywhere;">
                                                    <p style="font-size: var(--text-base); color: var(--color-text); font-weight: 500;">
                                                        {{ $row['counterpartyName'] ?? '—' }}
                                                    </p>
                                                    <p style="font-size: var(--text-xs); color: var(--color-text-muted); margin-top: 2px;">
                                                        {{ $postedAt ? $postedAt->translatedFormat('d M Y') : '—' }}
                                                        @if (! empty($row['accountName']))
                                                            · {{ $row['accountName'] }}
                                                        @endif
                                                    </p>
                                                    @if (! empty($row['note']))
                                                        <p style="font-size: var(--text-base); color: var(--color-text-muted); font-style: italic; margin-top: 2px;">{{ $row['note'] }}</p>
                                                    @endif
                                                </div>
                                                <div class="flex shrink-0 flex-col items-end gap-1">
                                                    <span class="kpi-number" style="font-size: var(--text-base); font-weight: 600; color: var(--color-text);">
                                                        {{ Money::ofMinor(abs($settledMinor), $settledCurrency)->format() }}
                                                    </span>
                                                    @if ($hasOverride)
                                                        <span class="tax-badge--amber">→ {{ $row['taxYearOverride'] }}</span>
                                                    @endif
                                                </div>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </details>
                    @endforeach
                </div>

            @endif {{-- itemCount === 0 --}}
        @endif {{-- $data !== null --}}

    </div>
</div>
