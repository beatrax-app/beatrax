{{--
    Per-source consolidated preview section — one section per
    ConsolidatedPreviewSection DTO in the FirstImportStep batch.
    Renders the section eyebrow ("FROM YOUR BANK STATEMENT · N ROWS ·
    ✓ READY"), an inline summary line, and a sampleRows table.

    Reuses the locked preview-row primitives shipped in earlier phases:
    `.ptype-chip`, `.cat-chip`, `.desc-fallback`, `.funding-tag`. The
    section never reaches into the live row's edit affordances —
    everything here is read-only since the user is on the final review
    surface; per-row category / counterparty edits live on the
    standalone `/imports/{id}/preview` page.

    Status variants:
      - ready    — at least one row was returned by the preview cache;
                   the eyebrow gets a "✓ READY" emerald suffix.
      - empty    — every contributing run was empty (all rows already
                   in the ledger); section eyebrow stays muted; body
                   reads "This statement is empty.".
      - error    — at least one contributing run's cache was missing
                   / expired; section body shows a rose-tinted "Try a
                   different file" prompt.
      - filtered — the wizard removed the section (stale window or
                   already-confirmed-elsewhere). Reserved for future
                   per-section opt-outs; renders the muted "we left
                   it out" note.

    Props:
      :section — the ConsolidatedPreviewSection DTO instance.
--}}
@use('Modules\Ingestion\Public\Enums\SourceFormat')
@use('Modules\Ledger\Public\ValueObjects\Money')
@use('Modules\Ledger\Public\Services\BaseCurrency')
@use('Modules\Core\Public\Support\Lang')
@props(['section'])
@php
    /** @var \Modules\Import\Public\Dto\ConsolidatedPreviewSection $section */

    $eyebrowLabel = match ($section->sourceFormat) {
        SourceFormat::Camt053->value, SourceFormat::Mt940->value, SourceFormat::AsnCsv->value, SourceFormat::IngCsv->value => Lang::get('onboarding::first_import.section.from_bank'),
        'ics-pdf' => Lang::get('onboarding::first_import.section.from_ics'),
        'paypal-csv' => Lang::get('onboarding::first_import.section.from_paypal'),
        default => Lang::get('onboarding::first_import.section.from_prefix').strtoupper(str_replace('-', ' ', $section->sourceFormat)),
    };

    $rowCount = $section->totalRows;
    $statusBadge = match ($section->status) {
        'ready' => Lang::get('onboarding::first_import.section.badge_ready'),
        'empty' => Lang::get('onboarding::first_import.section.badge_empty'),
        'error' => Lang::get('onboarding::first_import.section.badge_error'),
        'filtered' => Lang::get('onboarding::first_import.section.badge_filtered'),
        default => '',
    };

    $eyebrowClass = match ($section->status) {
        'filtered' => 'preview-section-eyebrow filtered',
        default => 'preview-section-eyebrow',
    };

    $badgeClass = match ($section->status) {
        'ready' => 'ready',
        'filtered' => 'filtered',
        'error' => 'rose',
        default => '',
    };
@endphp
<section
    class="preview-section"
    aria-labelledby="preview-section-{{ $section->sourceFormat }}-eyebrow"
>
    <p
        id="preview-section-{{ $section->sourceFormat }}-eyebrow"
        class="{{ $eyebrowClass }}"
    >
        <span class="preview-section-label">{{ $eyebrowLabel }}</span>
        <span class="preview-section-count">· {{ $rowCount }} {{ Lang::choice('onboarding::first_import.section.row', $rowCount) }}</span>
        @if ($statusBadge !== '')
            <span class="{{ $badgeClass }}">· {{ $statusBadge }}</span>
        @endif
    </p>

    @if ($section->status === 'error')
        {{-- The parser's own words where it has them: it names the format it
             expected and what to re-download, which the generic line cannot.
             It was already being written to the log and thrown away here. --}}
        <p class="preview-section-error" role="alert">
            {{ $section->error ?? Lang::get('onboarding::first_import.section.error_body') }}
        </p>
    @elseif ($section->status === 'empty')
        <p class="preview-section-empty">{{ Lang::get('onboarding::first_import.section.empty_body') }}</p>
    @elseif ($section->status === 'filtered')
        <p class="preview-section-filtered">
            {{ Lang::get('onboarding::first_import.section.filtered_body') }}
        </p>
    @else
        <table class="preview-section-table">
            <thead>
                <tr>
                    <th scope="col">{{ Lang::get('onboarding::first_import.section.col_date') }}</th>
                    <th scope="col">{{ Lang::get('onboarding::first_import.section.col_type') }}</th>
                    <th scope="col">{{ Lang::get('onboarding::first_import.section.col_counterparty') }}</th>
                    <th scope="col">{{ Lang::get('onboarding::first_import.section.col_amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($section->sampleRows as $row)
                    @php
                        /** @var \Modules\Import\Public\Dto\PreviewRowDto $row */
                        $paymentType = $row->paymentType;
                        $chipLabel = $paymentType !== null ? $paymentType->chipLabel() : '— —';
                        $chipClass = $paymentType !== null ? $paymentType->chipClass() : 'unknown';
                        $counterpartyDisplay = $row->aliasFriendlyName ?? $row->counterpartyName;
                        if ($counterpartyDisplay === null || $counterpartyDisplay === '') {
                            $counterpartyDisplay = $row->counterpartyIban ?? $row->description ?? '—';
                        }
                        // Through Money like every other amount in the app. The
                        // hand-rolled number_format here wrote US separators, so
                        // the one table nobody had ever seen with data on a
                        // device read -€1,154.13 beside a ledger saying € -13,50.
                        $amountFormatted = $row->amountMinor === null
                            ? '—'
                            : Money::ofMinor($row->amountMinor, $row->currency ?? BaseCurrency::value())->format();
                    @endphp
                    <tr>
                        <td class="preview-row-date">{{ $row->bookedAt ?? '—' }}</td>
                        <td>
                            <span class="ptype-chip {{ $chipClass }}">{{ $chipLabel }}</span>
                        </td>
                        <td class="preview-row-counterparty">
                            @if ($row->counterpartyName === null || $row->counterpartyName === '')
                                <span class="desc-fallback">{{ $counterpartyDisplay }}</span>
                            @else
                                <span>{{ $counterpartyDisplay }}</span>
                            @endif
                            @if ($row->counterpartyIban !== null && $row->counterpartyIban !== '')
                                <span class="funding-tag">{{ $row->counterpartyIban }}</span>
                            @endif
                        </td>
                        <td class="preview-row-amount">{{ $amountFormatted }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @php
            $shownCount = count($section->sampleRows);
            $remaining = max(0, $section->totalRows - $shownCount);
        @endphp

        @if ($remaining > 0)
            <div class="preview-section-more-actions">
                <button
                    type="button"
                    class="preview-section-load-more"
                    wire:click="loadMoreRows('{{ $section->sourceFormat }}')"
                >
                    {{ Lang::get('onboarding::first_import.section.load_more', ['remaining' => $remaining]) }}
                </button>
            </div>
        @elseif ($shownCount > 0 && $section->totalRows > 0)
            <p class="preview-section-more">{{ Lang::get('onboarding::first_import.section.rows_shown', ['count' => $shownCount]) }}</p>
        @endif
    @endif
</section>
