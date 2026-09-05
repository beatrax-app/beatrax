@use('Modules\Core\Public\Support\Iban')
@use('Modules\Import\Public\Enums\PreviewSectionStatus')
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
      - error    — nothing behind this section survived: every run was
                   left out, or its cache was missing / expired. The body
                   shows a rose-tinted "Try a different file" prompt.

    Props:
      :section — the ConsolidatedPreviewSection DTO instance.
--}}
@use('Modules\Ingestion\Public\Enums\SourceFormat')
@use('Modules\Ingestion\Public\Services\CsvPresetRegistry')
@use('Modules\Ledger\Public\ValueObjects\Money')
@use('Modules\Ledger\Public\Services\BaseCurrency')
@use('Modules\Core\Public\Support\Lang')
@props(['section'])
@php
    /** @var \Modules\Import\Public\Dto\ConsolidatedPreviewSection $section */

    $eyebrowLabel = match ($section->sourceFormat) {
        SourceFormat::Camt053->value, SourceFormat::Mt940->value, CsvPresetRegistry::ASN, CsvPresetRegistry::ING_NL => Lang::get('onboarding::first_import.section.from_bank'),
        SourceFormat::IcsPdf->value => Lang::get('onboarding::first_import.section.from_ics'),
        SourceFormat::PaypalCsv->value => Lang::get('onboarding::first_import.section.from_paypal'),
        default => Lang::get('onboarding::first_import.section.from_prefix').strtoupper(str_replace('-', ' ', $section->sourceFormat)),
    };

    $rowCount = $section->totalRows;
    $statusBadge = match ($section->status) {
        PreviewSectionStatus::Ready => Lang::get('onboarding::first_import.section.badge_ready'),
        PreviewSectionStatus::Empty => Lang::get('onboarding::first_import.section.badge_empty'),
        PreviewSectionStatus::Error => Lang::get('onboarding::first_import.section.badge_error'),
    };

    $badgeClass = match ($section->status) {
        PreviewSectionStatus::Ready => 'ready',
        PreviewSectionStatus::Error => 'rose',
        PreviewSectionStatus::Empty => '',
    };
@endphp
<section
    aria-labelledby="preview-section-{{ $section->sourceFormat }}-eyebrow"
>
    <p
        id="preview-section-{{ $section->sourceFormat }}-eyebrow"
        class="preview-section-eyebrow"
    >
        <span class="preview-section-label">{{ $eyebrowLabel }}</span>
        <span class="preview-section-count">· {{ Lang::choice('onboarding::first_import.section.row', $rowCount) }}</span>
        <span class="{{ $badgeClass }}">· {{ $statusBadge }}</span>
    </p>

    @if ($section->status === PreviewSectionStatus::Error)
        {{-- The parser's own words where it has them: it names the format it
             expected and what to re-download, which the generic line cannot.
             It was already being written to the log and thrown away here. --}}
        <p class="preview-section-error" role="alert">
            {{ $section->error ?? Lang::get('onboarding::first_import.section.error_body') }}
        </p>
    @elseif ($section->status === PreviewSectionStatus::Empty)
        <p class="preview-section-empty">{{ Lang::get('onboarding::first_import.section.empty_body') }}</p>
    @else
        {{-- A file the confirm would refuse is left out whole, so a section
             holding one alongside a file that read cleanly is READY on the
             other one's rows. Without this the count under the eyebrow is
             simply lower than what the reader uploaded, saying nothing. --}}
        @if ($section->leftOutRunCount > 0)
            <p class="preview-section-error" role="status">
                {{ Lang::choice('onboarding::first_import.section.left_out', $section->leftOutRunCount, ['reason' => $section->error ?? Lang::get('onboarding::first_import.section.error_body')]) }}
            </p>
        {{-- A row that failed is not a file that was left out: the file is
             here, the rest of it commits, and saying otherwise sent the reader
             looking for a statement that was never dropped. --}}
        @elseif ($section->error !== null)
            <p class="preview-section-error" role="status">
                {{ Lang::get('onboarding::first_import.section.rows_skipped', ['reason' => $section->error]) }}
            </p>
        @endif
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
                            $counterpartyDisplay = $row->counterpartyIban !== null
                                ? Iban::grouped($row->counterpartyIban)
                                : ($row->description ?? '—');
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
                        <td>{{ $row->postedAt ?? '—' }}</td>
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
                                <span class="funding-tag">{{ Iban::grouped($row->counterpartyIban) }}</span>
                            @endif
                        </td>
                        <td>{{ $amountFormatted }}</td>
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
            <p class="preview-section-more">{{ Lang::choice('onboarding::first_import.section.rows_shown', $shownCount) }}</p>
        @endif
    @endif
</section>
