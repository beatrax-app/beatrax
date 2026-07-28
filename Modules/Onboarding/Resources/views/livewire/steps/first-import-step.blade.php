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
@php
    /** @var \Modules\Import\Public\Dto\ConsolidatedPreviewBatch $preview */
    /** @var list<\Modules\Import\Public\Dto\StartingBalanceCandidate> $startingBalances */
    /** @var array<int, array{minor: int, date: string}> $balanceConfirmations */
    /** @var string $commitError */
    /** @var bool $isCommitting */
    /** @var array<int, array{label: string, short: string, currency: string}> $accountMeta */

    $hasAnyReadySection = false;
    foreach ($preview->sections as $section) {
        if ($section->status === 'ready') {
            $hasAnyReadySection = true;
            break;
        }
    }

    $sourceCount = 0;
    foreach ($preview->sections as $section) {
        if ($section->status === 'ready' || $section->status === 'empty') {
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
        $isCommitting => 'Committing…',
        $preview->dedupedTotalCount > 0 => sprintf('Commit everything (%d transactions) →', $preview->dedupedTotalCount),
        default => 'Commit everything (—) →',
    };

    $commitDisabled = $isCommitting || ! $hasAnyReadySection;
@endphp
<section class="wiz-step wiz-step-first-import" aria-labelledby="wiz-first-import-h1">
    <x-onboarding::wiz-card :wide="true">
        <x-onboarding::wiz-eyebrow step="first-import" glyph="📥">Review &amp; commit</x-onboarding::wiz-eyebrow>
        <h1 id="wiz-first-import-h1" class="wiz-h1">
            Review everything we found
        </h1>
        <p class="wiz-lede">
            <span class="tabular-nums">{{ $preview->dedupedTotalCount }}</span> transactions across
            <span class="tabular-nums">{{ $sourceCount }}</span>
            {{ $sourceCount === 1 ? 'source' : 'sources' }}.
            Confirm your starting balances, then commit.
        </p>

        @if ($preview->sections === [])
            <p class="wiz-empty">
                Nothing to review yet. Drop a statement on the earlier steps to see your transactions here.
            </p>
        @else
            @foreach ($preview->sections as $section)
                <section class="source-subcard {{ $section->status === 'empty' ? 'empty' : '' }}">
                    <x-onboarding::consolidated-preview-section :section="$section" />
                </section>
            @endforeach
        @endif

        @if ($detectedCount > 0)
            <section class="balance-section-subcard">
                <p class="preview-section-eyebrow">
                    🧮 STARTING BALANCES ·
                    <span class="tabular-nums">{{ $detectedCount }}</span>
                    {{ $detectedCount === 1 ? 'ACCOUNT DETECTED' : 'ACCOUNTS DETECTED' }}
                </p>
                <p class="starting-balance-lede">
                    We detected the starting balance for each account. Confirm or edit before we commit.
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
                                'currency' => 'EUR',
                            ];
                            $isConflict = count($candidates) > 1;
                            $cardState = $isConflict ? 'conflict' : 'detected';
                            $alternativeCandidates = [];
                            foreach ($candidates as $candidate) {
                                $sourceLabel = match ($candidate->sourceFormat) {
                                    'camt053' => 'CAMT.053',
                                    'mt940' => 'MT940',
                                    'asn-csv' => 'ASN CSV',
                                    'ing-csv' => 'ING CSV',
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
            <p class="commit-counter" aria-atomic="true" aria-live="polite">
                <strong class="tabular-nums">{{ $preview->dedupedTotalCount }}</strong>
                {{ $preview->dedupedTotalCount === 1 ? 'transaction' : 'transactions' }}
                to commit ·
                <span class="tabular-nums">{{ $preview->alreadyImportedCount }}</span>
                already imported
            </p>
            <button
                type="button"
                class="commit-btn-primary"
                wire:click="commitEverything"
                @if ($commitDisabled) aria-disabled="true" disabled @endif
            >
                {{ $commitButtonLabel }}
            </button>
        </div>
    </x-onboarding::wiz-card>
</section>
