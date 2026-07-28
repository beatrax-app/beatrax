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
@props(['section'])
@php
    /** @var \Modules\Import\Public\Dto\ConsolidatedPreviewSection $section */

    $eyebrowLabel = match ($section->sourceFormat) {
        'camt053', 'mt940', 'asn-csv', 'ing-csv' => 'FROM YOUR BANK STATEMENT',
        'ics-pdf' => 'FROM YOUR ICS CARD STATEMENTS',
        'paypal-csv' => 'FROM PAYPAL',
        default => 'FROM '.strtoupper(str_replace('-', ' ', $section->sourceFormat)),
    };

    $rowCount = $section->totalRows;
    $statusBadge = match ($section->status) {
        'ready' => '✓ READY',
        'empty' => 'EMPTY',
        'error' => 'NEEDS RE-UPLOAD',
        'filtered' => 'ALREADY IMPORTED',
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
        <span class="preview-section-count">· {{ $rowCount }} {{ $rowCount === 1 ? 'ROW' : 'ROWS' }}</span>
        @if ($statusBadge !== '')
            <span class="{{ $badgeClass }}">· {{ $statusBadge }}</span>
        @endif
    </p>

    @if ($section->status === 'error')
        <p class="preview-section-error" role="alert">
            We couldn't read all of the files for this source. Try a different file →
        </p>
    @elseif ($section->status === 'empty')
        <p class="preview-section-empty">This statement is empty.</p>
    @elseif ($section->status === 'filtered')
        <p class="preview-section-filtered">
            This statement was already imported elsewhere — we left it out.
        </p>
    @else
        <table class="preview-section-table">
            <thead>
                <tr>
                    <th scope="col">Date</th>
                    <th scope="col">Type</th>
                    <th scope="col">Counterparty</th>
                    <th scope="col">Amount</th>
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
                        $amountFormatted = '—';
                        if ($row->amountMinor !== null) {
                            $major = abs($row->amountMinor) / 100;
                            $sign = $row->amountMinor < 0 ? '-' : '';
                            $currency = $row->currency ?? 'EUR';
                            $symbol = match ($currency) {
                                'EUR' => '€',
                                'USD' => '$',
                                'GBP' => '£',
                                default => $currency.' ',
                            };
                            $amountFormatted = $sign.$symbol.number_format($major, 2, '.', ',');
                        }
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
                    Load more ({{ $remaining }} remaining)
                </button>
            </div>
        @elseif ($shownCount > 0 && $section->totalRows > 0)
            <p class="preview-section-more">{{ $shownCount }} rows shown</p>
        @endif
    @endif
</section>
