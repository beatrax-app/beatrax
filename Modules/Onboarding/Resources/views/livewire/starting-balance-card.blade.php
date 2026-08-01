{{--
    Starting-balance confirm card — one card per detected (or manual-
    entry) account, mounted by the first-import step inside the
    starting-balance section. Owns its own state machine: the parent
    only learns about a confirmation via the `starting-balance.confirmed`
    Livewire event, which the parent listens for and aggregates into a
    per-account `$balanceConfirmations` map.

    Five render variants drive off `$state`:
      - detected     — pre-filled value, Confirm + Edit affordances.
      - conflict     — radio list of alternative candidates from the
                       aggregator's tie-break surface.
      - editing      — inline number + date inputs with Cancel + Save.
      - confirmed    — collapsed single-line summary with Change link.
      - manual-entry — empty inputs + lede for the no-detector case.

    Currency rendering: the integer portion is rendered with
    tabular-nums + mono so amounts align across stacked cards. The
    decimal portion (e.g. `.56` of `€1,234.56`) stays tabular so a
    column of round euros and a column of €0.99-shaped amounts still
    line up.
--}}
@use('Modules\Ledger\Public\ValueObjects\Money')
@php
    /** @var int $accountId */
    /** @var string $accountLabel */
    /** @var string $accountShort */
    /** @var string $currency */
    /** @var ?int $detectedMinor */
    /** @var ?string $detectedDate */
    /** @var ?int $editedMinor */
    /** @var ?string $editedDate */
    /** @var string $state */
    /** @var bool $isConfirmed */
    /** @var string $dateWarning */
    /** @var string $validationError */
    /** @var list<array{minor: int, date: string, sourceLabel: string}> $alternativeCandidates */
    /** @var int $selectedConflictIndex */

    $cardClasses = 'balance-card';
    if ($isConfirmed) {
        $cardClasses .= ' confirmed';
    }

    $formatMinor = static function (int $minor, string $currency): string {
        $absoluteMajor = abs($minor) / Money::MINOR_UNITS_PER_MAJOR;
        $sign = $minor < 0 ? '-' : '';
        $symbol = match ($currency) {
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            default => $currency.' ',
        };

        return $sign.$symbol.number_format($absoluteMajor, 2, '.', ',');
    };
@endphp
<section
    class="{{ $cardClasses }}"
    aria-labelledby="balance-card-{{ $accountId }}-h3"
>
    <div class="balance-card-main">
        <p class="eyebrow">
            🧮 STARTING BALANCE
            <span class="funding-tag">{{ $accountShort }}</span>
            @if ($isConfirmed)
                <span class="ready" aria-label="confirmed">✓</span>
            @endif
        </p>

        @switch($state)
            @case('detected')
                <h3 id="balance-card-{{ $accountId }}-h3" class="balance-card-h3">
                    We detected your {{ $accountLabel }} started at
                </h3>
                @if ($detectedMinor !== null)
                    <p class="value">{{ $formatMinor($detectedMinor, $currency) }}</p>
                @endif
                @if ($detectedDate !== null)
                    <p class="date">on {{ $detectedDate }}</p>
                @endif
                <div class="balance-card-actions">
                    <button
                        type="button"
                        class="pill-btn-primary"
                        wire:click="confirm"
                    >Confirm</button>
                    <button
                        type="button"
                        class="edit-link"
                        wire:click="startEdit"
                    >Edit</button>
                </div>
                @break

            @case('conflict')
                <h3 id="balance-card-{{ $accountId }}-h3" class="balance-card-h3">
                    We saw two values for this account — which is right?
                </h3>
                <fieldset class="conflict-options">
                    <legend class="sr-only">Pick a starting balance</legend>
                    @foreach ($alternativeCandidates as $index => $candidate)
                        <label class="conflict-option">
                            <input
                                type="radio"
                                name="conflict-{{ $accountId }}"
                                value="{{ $index }}"
                                @if ($index === $selectedConflictIndex) checked @endif
                                wire:click="pickConflictCandidate({{ $index }})"
                            />
                            <span class="conflict-source">From {{ $candidate['sourceLabel'] }}:</span>
                            <span class="value">{{ $formatMinor($candidate['minor'], $currency) }}</span>
                            <span class="date">on {{ $candidate['date'] }}</span>
                        </label>
                    @endforeach
                </fieldset>
                <p class="conflict-helper">
                    We default to the earliest date. Pick the right one or edit manually.
                </p>
                <div class="balance-card-actions">
                    <button
                        type="button"
                        class="edit-link"
                        wire:click="startEdit"
                    >Edit manually</button>
                </div>
                @break

            @case('editing')
                <h3 id="balance-card-{{ $accountId }}-h3" class="balance-card-h3">
                    Edit your {{ $accountLabel }} starting balance
                </h3>
                <div class="balance-card-editor">
                    <label class="balance-card-input">
                        <span class="balance-card-input-label">STARTING BALANCE</span>
                        <input
                            type="number"
                            step="1"
                            inputmode="numeric"
                            wire:model.live="editedMinor"
                            class="balance-card-amount-input"
                            placeholder="{{ $detectedMinor }}"
                        />
                        <span class="balance-card-currency-suffix">{{ $currency }} (minor units)</span>
                    </label>
                    <label class="balance-card-input">
                        <span class="balance-card-input-label">ON DATE</span>
                        <input
                            type="date"
                            wire:model.live="editedDate"
                            class="balance-card-date-input"
                        />
                    </label>
                </div>
                @if ($dateWarning !== '')
                    <span class="warn" role="alert">{{ $dateWarning }}</span>
                @endif
                @if ($validationError !== '')
                    <p class="wiz-error" role="alert">{{ $validationError }}</p>
                @endif
                <div class="balance-card-actions">
                    <button
                        type="button"
                        class="pill-btn-ghost"
                        wire:click="cancelEdit"
                    >Cancel</button>
                    <button
                        type="button"
                        class="pill-btn-primary"
                        wire:click="save"
                    >Save</button>
                </div>
                @break

            @case('confirmed')
                <h3 id="balance-card-{{ $accountId }}-h3" class="balance-card-h3 balance-card-h3--collapsed">
                    <span class="ready">✓</span>
                    {{ $accountLabel }} ·
                    @if ($editedMinor !== null)
                        <span class="value">{{ $formatMinor($editedMinor, $currency) }}</span>
                    @endif
                    @if ($editedDate !== null)
                        <span class="date">on {{ $editedDate }}</span>
                    @endif
                </h3>
                @if ($dateWarning !== '')
                    <span class="warn" role="alert">{{ $dateWarning }}</span>
                @endif
                <div class="balance-card-actions">
                    <button
                        type="button"
                        class="edit-link"
                        wire:click="startEdit"
                    >Change</button>
                </div>
                @break

            @case('manual-entry')
                <h3 id="balance-card-{{ $accountId }}-h3" class="balance-card-h3">
                    Enter your {{ $accountLabel }} starting balance manually
                </h3>
                <p class="balance-card-lede">
                    We couldn't auto-detect a starting balance for this account. Enter one manually or skip.
                </p>
                <div class="balance-card-editor">
                    <label class="balance-card-input">
                        <span class="balance-card-input-label">STARTING BALANCE</span>
                        <input
                            type="number"
                            step="1"
                            inputmode="numeric"
                            wire:model.live="editedMinor"
                            class="balance-card-amount-input"
                            placeholder="0"
                        />
                        <span class="balance-card-currency-suffix">{{ $currency }} (minor units)</span>
                    </label>
                    <label class="balance-card-input">
                        <span class="balance-card-input-label">ON DATE</span>
                        <input
                            type="date"
                            wire:model.live="editedDate"
                            class="balance-card-date-input"
                        />
                    </label>
                </div>
                @if ($dateWarning !== '')
                    <span class="warn" role="alert">{{ $dateWarning }}</span>
                @endif
                @if ($validationError !== '')
                    <p class="wiz-error" role="alert">{{ $validationError }}</p>
                @endif
                <div class="balance-card-actions">
                    <button
                        type="button"
                        class="pill-btn-primary"
                        wire:click="save"
                    >Save</button>
                </div>
                @break

            @default
                <p class="wiz-error" role="alert">Unknown card state. Reload the wizard.</p>
        @endswitch
    </div>
</section>
