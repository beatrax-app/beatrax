@use('Modules\Core\Public\Support\Lang')
@use('Modules\Import\Public\Enums\PreviewSectionStatus')
{{--
    First-import step — wizard step 5. The consolidated commit
    surface. No file uploads happen here; this page is the closing
    "review and commit" view that takes every stashed ImportRun (bank
    via CAMT/MT940/CSV, ICS via PDFs, future PayPal) and turns it into
    a single primary action: "Commit everything (N transactions) →".

    Layout:
      - Page H1 + lede with tabular counters.
      - One consolidated-preview-section partial per source format.
      - One StartingBalanceCard child component per detected account
        (and per detected-but-needs-manual-entry account).
      - Commit footer with the live deduplicated counter, Cancel
        button, and the single primary CTA.

    The `wiz-card--wide` class is the locked 1120px width exception
    for this step (UI-SPEC §"Density rules"). Every other wizard step
    is 620px; this one needs the room for the preview table.
--}}
@use('Modules\Ingestion\Public\Enums\SourceFormat')
@use('Modules\Ledger\Public\Services\BaseCurrency')
@php
    /** @var \Modules\Import\Public\Dto\ConsolidatedPreviewBatch $preview */
    /** @var list<\Modules\Import\Public\Dto\StartingBalanceCandidate> $startingBalances */
    /** @var array<int, array{minor: int, date: string}> $balanceConfirmations */
    /** @var string $commitError */
    /** @var bool $isCommitting */
    /** @var array<int, array{label: string, short: string, currency: string}> $accountMeta */

    $hasAnyReadySection = false;
    foreach ($preview->sections as $section) {
        if ($section->status === PreviewSectionStatus::Ready) {
            $hasAnyReadySection = true;
            break;
        }
    }

    $sourceCount = 0;
    foreach ($preview->sections as $section) {
        if ($section->status === PreviewSectionStatus::Ready || $section->status === PreviewSectionStatus::Empty) {
            $sourceCount++;
        }
    }

    // Group starting balances by accountId — when the aggregator
    // returned two candidates for one account, the second entry is
    // the conflict alternative we surface in the card's conflict
    // variant.
    $balancesByAccount = [];
    foreach ($startingBalances as $candidate) {
        $balancesByAccount[$candidate->accountId][] = $candidate;
    }

    $detectedCount = count($balancesByAccount);

    $commitButtonLabel = match (true) {
        $isCommitting => Lang::get('onboarding::first_import.commit_committing'),
        $preview->dedupedTotalCount > 0 => Lang::choice('onboarding::first_import.commit_count', $preview->dedupedTotalCount),
        default => Lang::get('onboarding::first_import.commit_empty'),
    };

    $commitDisabled = $isCommitting || ! $hasAnyReadySection;
@endphp
<section class="wiz-step wiz-step-first-import" aria-labelledby="wiz-first-import-h1">
    <x-onboarding::wiz-card :wide="true">
        <x-onboarding::wiz-eyebrow step="first-import" glyph="📥">{{ Lang::get('onboarding::first_import.eyebrow') }}</x-onboarding::wiz-eyebrow>
        <h1 id="wiz-first-import-h1" class="wiz-h1">
            {{ Lang::get('onboarding::first_import.h1') }}
        </h1>
        <p class="wiz-lede tabular-nums">
            {{ Lang::get('onboarding::first_import.lede_counts', [
                'transactions' => Lang::choice('onboarding::first_import.txn', $preview->dedupedTotalCount),
                'sources' => Lang::choice('onboarding::first_import.source', $sourceCount),
            ]) }}
            {{ Lang::get('onboarding::first_import.lede_confirm') }}
        </p>

        @if ($preview->sections === [])
            <p class="wiz-empty">
                {{ Lang::get('onboarding::first_import.empty') }}
            </p>
        @else
            @foreach ($preview->sections as $section)
                <section class="source-subcard {{ $section->status === PreviewSectionStatus::Empty ? 'empty' : '' }}">
                    <x-onboarding::consolidated-preview-section :section="$section" />
                </section>
            @endforeach
        @endif

        @if ($detectedCount > 0)
            <section class="balance-section-subcard">
                <p class="preview-section-eyebrow">
                    <span>{{ Lang::get('onboarding::first_import.sb_eyebrow_label') }}</span>
                    <span class="tabular-nums">{{ Lang::choice('onboarding::first_import.account_detected', $detectedCount) }}</span>
                </p>
                <p class="starting-balance-lede">
                    {{ Lang::get('onboarding::first_import.sb_lede') }}
                </p>
                <div class="starting-balance-stack">
                    @foreach ($balancesByAccount as $accountId => $candidates)
                        @php
                            /** @var int $accountId */
                            /** @var list<\Modules\Import\Public\Dto\StartingBalanceCandidate> $candidates */
                            $primary = $candidates[0];
                            $meta = $accountMeta[$accountId] ?? [
                                'label' => 'account',
                                'short' => '· · · ·',
                                'currency' => BaseCurrency::value(),
                            ];
                            $isConflict = count($candidates) > 1;
                            $cardState = $isConflict ? 'conflict' : 'detected';
                            $alternativeCandidates = [];
                            foreach ($candidates as $candidate) {
                                $sourceLabel = match ($candidate->sourceFormat) {
                                    SourceFormat::Camt053->value => 'CAMT.053',
                                    SourceFormat::Mt940->value => 'MT940',
                                    SourceFormat::AsnCsv->value => 'ASN CSV',
                                    SourceFormat::IngCsv->value => 'ING CSV',
                                    'ics-pdf' => 'ICS PDF',
                                    'paypal-csv' => 'PayPal CSV',
                                    default => $candidate->sourceFormat,
                                };
                                $alternativeCandidates[] = [
                                    'minor' => $candidate->openingBalanceMinor,
                                    'date' => $candidate->openingBalanceDate,
                                    'sourceLabel' => $sourceLabel,
                                ];
                            }
                        @endphp
                        <livewire:onboarding.starting-balance-card
                            :key="'sb-'.$accountId"
                            :account-id="$accountId"
                            :account-label="$meta['label']"
                            :account-short="$meta['short']"
                            :currency="$meta['currency']"
                            :detected-minor="$primary->openingBalanceMinor"
                            :detected-date="$primary->openingBalanceDate"
                            :state="$cardState"
                            :alternative-candidates="$alternativeCandidates"
                        />
                    @endforeach
                </div>
            </section>
        @endif

        @if ($commitError !== '')
            <p class="wiz-error" role="alert">{{ $commitError }}</p>
        @endif

        <div class="commit-footer">
            <p class="commit-counter tabular-nums" aria-atomic="true" aria-live="polite">
                <strong>{{ Lang::choice('onboarding::first_import.txn', $preview->dedupedTotalCount) }}</strong>
                {{ Lang::get('onboarding::first_import.to_commit') }}
                {{ Lang::choice('onboarding::first_import.already_imported', $preview->alreadyImportedCount) }}
            </p>
            {{-- The commit button is disabled whenever nothing is ready, which
                 is the ordinary state for someone who skipped the connectors.
                 The skip sits beside it so the step always has one enabled way
                 forward; the header's "continue later" leaves the wizard and is
                 not the same affordance. --}}
            <div class="commit-footer-actions">
                <button
                    type="button"
                    class="pill-btn-ghost"
                    wire:click="skip"
                >
                    {{ Lang::get('onboarding::first_import.skip') }}
                </button>
                <button
                    type="button"
                    class="commit-btn-primary"
                    wire:click="commitEverything"
                    @if ($commitDisabled) aria-disabled="true" disabled @endif
                >
                    {{ $commitButtonLabel }}
                </button>
            </div>
        </div>
    </x-onboarding::wiz-card>
</section>
