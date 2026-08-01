@use('Modules\Core\Public\Support\Lang')
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
            {{ Lang::get('onboarding::starting_balance.eyebrow') }}
            <span class="funding-tag">{{ $accountShort }}</span>
            @if ($isConfirmed)
                <span class="ready" aria-label="{{ Lang::get('onboarding::starting_balance.confirmed_aria') }}">✓</span>
            @endif
        </p>

        @switch($state)
            @case('detected')
                <h3 id="balance-card-{{ $accountId }}-h3" class="balance-card-h3">
                    {{ Lang::get('onboarding::starting_balance.detected_h3', ['label' => $accountLabel]) }}
                </h3>
                @if ($detectedMinor !== null)
                    <p class="value">{{ $formatMinor($detectedMinor, $currency) }}</p>
                @endif
                @if ($detectedDate !== null)
                    <p class="date">{{ Lang::get('onboarding::starting_balance.on_date', ['date' => $detectedDate]) }}</p>
                @endif
                <div class="balance-card-actions">
                    <button
                        type="button"
                        class="pill-btn-primary"
                        wire:click="confirm"
                    >{{ Lang::get('onboarding::starting_balance.confirm') }}</button>
                    <button
                        type="button"
                        class="edit-link"
                        wire:click="startEdit"
                    >{{ Lang::get('onboarding::starting_balance.edit') }}</button>
                </div>
                @break

            @case('conflict')
                <h3 id="balance-card-{{ $accountId }}-h3" class="balance-card-h3">
                    {{ Lang::get('onboarding::starting_balance.conflict_h3') }}
                </h3>
                <fieldset class="conflict-options">
                    <legend class="sr-only">{{ Lang::get('onboarding::starting_balance.conflict_legend') }}</legend>
                    @foreach ($alternativeCandidates as $index => $candidate)
                        <label class="conflict-option">
                            <input
                                type="radio"
                                name="conflict-{{ $accountId }}"
                                value="{{ $index }}"
                                @if ($index === $selectedConflictIndex) checked @endif
                                wire:click="pickConflictCandidate({{ $index }})"
                            />
                            <span class="conflict-source">{{ Lang::get('onboarding::starting_balance.conflict_from', ['source' => $candidate['sourceLabel']]) }}</span>
                            <span class="value">{{ $formatMinor($candidate['minor'], $currency) }}</span>
                            <span class="date">{{ Lang::get('onboarding::starting_balance.on_date', ['date' => $candidate['date']]) }}</span>
                        </label>
                    @endforeach
                </fieldset>
                <p class="conflict-helper">
                    {{ Lang::get('onboarding::starting_balance.conflict_helper') }}
                </p>
                <div class="balance-card-actions">
                    <button
                        type="button"
                        class="edit-link"
                        wire:click="startEdit"
                    >{{ Lang::get('onboarding::starting_balance.edit_manually') }}</button>
                </div>
                @break

            @case('editing')
                <h3 id="balance-card-{{ $accountId }}-h3" class="balance-card-h3">
                    {{ Lang::get('onboarding::starting_balance.editing_h3', ['label' => $accountLabel]) }}
                </h3>
                <div class="balance-card-editor">
                    <label class="balance-card-input">
                        <span class="balance-card-input-label">{{ Lang::get('onboarding::starting_balance.input_label') }}</span>
                        <input
                            type="number"
                            step="1"
                            inputmode="numeric"
                            wire:model.live="editedMinor"
                            class="balance-card-amount-input"
                            placeholder="{{ $detectedMinor }}"
                        />
                        <span class="balance-card-currency-suffix">{{ $currency }} {{ Lang::get('onboarding::starting_balance.minor_units') }}</span>
                    </label>
                    <label class="balance-card-input">
                        <span class="balance-card-input-label">{{ Lang::get('onboarding::starting_balance.on_date_label') }}</span>
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
                    >{{ Lang::get('onboarding::starting_balance.cancel') }}</button>
                    <button
                        type="button"
                        class="pill-btn-primary"
                        wire:click="save"
                    >{{ Lang::get('onboarding::starting_balance.save') }}</button>
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
                        <span class="date">{{ Lang::get('onboarding::starting_balance.on_date', ['date' => $editedDate]) }}</span>
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
                    >{{ Lang::get('onboarding::starting_balance.change') }}</button>
                </div>
                @break

            @case('manual-entry')
                <h3 id="balance-card-{{ $accountId }}-h3" class="balance-card-h3">
                    {{ Lang::get('onboarding::starting_balance.manual_h3', ['label' => $accountLabel]) }}
                </h3>
                <p class="balance-card-lede">
                    {{ Lang::get('onboarding::starting_balance.manual_lede') }}
                </p>
                <div class="balance-card-editor">
                    <label class="balance-card-input">
                        <span class="balance-card-input-label">{{ Lang::get('onboarding::starting_balance.input_label') }}</span>
                        <input
                            type="number"
                            step="1"
                            inputmode="numeric"
                            wire:model.live="editedMinor"
                            class="balance-card-amount-input"
                            placeholder="0"
                        />
                        <span class="balance-card-currency-suffix">{{ $currency }} {{ Lang::get('onboarding::starting_balance.minor_units') }}</span>
                    </label>
                    <label class="balance-card-input">
                        <span class="balance-card-input-label">{{ Lang::get('onboarding::starting_balance.on_date_label') }}</span>
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
                    >{{ Lang::get('onboarding::starting_balance.save') }}</button>
                </div>
                @break

            @default
                <p class="wiz-error" role="alert">{{ Lang::get('onboarding::starting_balance.unknown_state') }}</p>
        @endswitch
    </div>
</section>
