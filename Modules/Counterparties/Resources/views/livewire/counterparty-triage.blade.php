@use('Modules\Core\Public\Support\Lang')
@use('Carbon\CarbonImmutable')
{{--
    /counterparties/triage focused single-card queue.

    Keyboard bindings respect the project-wide input carve-out (when
    focus is inside INPUT/TEXTAREA/contentEditable, the keys go to the
    field, not the handler) via Alpine focus-state tracking on the
    root element. The Y/N/S/→ wire actions only fire when focus is
    outside the manual-label fieldset.

    All copy is verbatim from 17-UI-SPEC.md (Counterparty triage
    table).

    Variables exposed by `CounterpartyTriage::render()`:
      $current             ?Counterparty
      $suggestion          ?TriageSuggestion
      $showSuggestion      bool
      $seen, $total, $percent, $minutesRemaining, $remainingCount  int
      $recentTransactions  list<\stdClass>
      $queueEmpty          bool
--}}
@use('Modules\Ledger\Public\ValueObjects\Money')
@php

    /**
     * IBAN-masking helper for the triage card display. Keeps the
     * country prefix + bank ID + last two digits visible, masks the
     * middle. Mirrors the UI-SPEC format `NL · ·· INGB ···· ···· 47`.
     */
    $maskIban = static function (?string $iban): string {
        if ($iban === null || $iban === '') {
            return '—';
        }
        $clean = strtoupper(preg_replace('/\s+/', '', $iban) ?? $iban);
        if (strlen($clean) < 12) {
            return $clean;
        }
        $country = substr($clean, 0, 2);
        $bank = substr($clean, 4, 4);
        $tail = substr($clean, -2);

        return $country.' · ·· '.$bank.' ···· ···· '.$tail;
    };
@endphp

<div
    class="triage-shell"
    x-data="{ inputFocused: false }"
    x-on:focusin.capture="inputFocused = ['INPUT','TEXTAREA'].includes($event.target.tagName) || $event.target.isContentEditable"
    x-on:focusout.capture="inputFocused = false"
    x-on:keydown.window.s.prevent="if (!inputFocused) $wire.skipForNow()"
    x-on:keydown.window.arrow-right.prevent="if (!inputFocused) $wire.nextItem()"
    x-on:keydown.window.escape="if (!inputFocused) window.location.href = '{{ route('counterparties.index') }}'"
    @if ($showSuggestion && $current !== null && $suggestion !== null)
        x-on:keydown.window.y.prevent="if (!inputFocused) $wire.acceptSuggestion()"
        x-on:keydown.window.n.prevent="if (!inputFocused) $wire.rejectSuggestion()"
    @endif
>
    <header style="display: flex; align-items: baseline; justify-content: space-between; gap: var(--space-4); flex-wrap: wrap;">
        <h1 style="font-size: var(--text-xl); font-weight: 600; color: var(--color-text); margin: 0;">
            {{ Lang::get('counterparties::triage.heading') }}
        </h1>
        {{-- Only when there is something to be through. With an empty queue
             this read "0 of 0 · 100 % · ~1 min remaining" over a full bar —
             asserting both that work existed and that it was done. The
             all-caught-up card below already says the true thing. --}}
        @if ($total > 0)
            <span style="font-size: var(--text-sm); color: var(--color-text-muted); font-variant-numeric: tabular-nums;">
                {{ Lang::get('counterparties::triage.progress', ['seen' => $seen, 'total' => $total, 'percent' => $percent, 'minutes' => $minutesRemaining]) }}
            </span>
        @endif
    </header>

    @if ($total > 0)
        <div class="progress-bar" aria-label="{{ Lang::get('counterparties::triage.progress_aria') }}">
            <div class="progress-fill" style="width: {{ $percent }}%;"></div>
        </div>
    @endif

    @if ($queueEmpty)
        <section class="triage-card" aria-label="{{ Lang::get('counterparties::triage.all_caught_aria') }}">
            <h2 style="font-size: var(--text-xl); font-weight: 600; color: var(--color-text); margin: 0;">
                {{ Lang::get('counterparties::triage.all_caught_heading') }}
            </h2>
            <p style="margin: 0;">
                <a href="{{ route('counterparties.index') }}" style="font-size: var(--text-sm); color: var(--color-text); text-decoration: underline;">{{ Lang::get('counterparties::triage.back_to_index') }}</a>
            </p>
        </section>
    @else
        <section class="triage-card">
            <header class="triage-head">
                <span class="triage-iban">{{ $maskIban($current->iban) }}</span>
                <x-counterparties::type-chip type="unknown" />
            </header>

            <p class="triage-meta">
                {{ Lang::get('counterparties::triage.meta', [
                    'count' => count($recentTransactions),
                    'date' => ! empty($recentTransactions) ? CarbonImmutable::parse((string) $recentTransactions[0]->posted_at)->isoFormat('L') : '—',
                ]) }}
            </p>

            @if ($showSuggestion && $suggestion !== null)
                @php
                    $bannerClass = match ($suggestion->confidence) {
                        'medium' => 'suggestion medium',
                        'low' => 'suggestion low',
                        default => 'suggestion',
                    };
                    $bannerCopy = match ($suggestion->confidence) {
                        'medium' => Lang::get('counterparties::triage.suggestion_medium', ['name' => $suggestion->suggestedCounterpartyName]),
                        'low' => Lang::get('counterparties::triage.suggestion_low', ['name' => $suggestion->suggestedCounterpartyName]),
                        default => Lang::get('counterparties::triage.suggestion_high', ['name' => $suggestion->suggestedCounterpartyName]),
                    };
                @endphp
                <section class="{{ $bannerClass }}" aria-label="{{ Lang::get('counterparties::triage.suggested_aria') }}">
                    <span>{!! preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', e($bannerCopy)) !!}</span>
                    <span style="font-size: var(--text-xs); color: inherit; opacity: 0.85;">
                        {{ $suggestion->reasoning }}
                    </span>
                </section>

                <div class="triage-actions">
                    <button
                        type="button"
                        class="pill-btn-primary focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                        aria-keyshortcuts="Y"
                        wire:click="acceptSuggestion"
                    >{{ Lang::get('counterparties::triage.yes_link', ['name' => $suggestion->suggestedCounterpartyName]) }}</button>
                    <button
                        type="button"
                        class="pill-btn-ghost focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                        aria-keyshortcuts="N"
                        wire:click="rejectSuggestion"
                    >{{ Lang::get('counterparties::triage.no_not', ['name' => $suggestion->suggestedCounterpartyName]) }}</button>
                </div>
            @endif

            <div class="triage-section">
                <h3 style="font-size: var(--text-xs); text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-text-muted); font-weight: 600; margin: 0;">
                    {{ Lang::get('counterparties::triage.recent_on_iban') }}
                </h3>
                @if (count($recentTransactions) === 0)
                    <p style="font-size: var(--text-sm); color: var(--color-text-muted); margin: 0;">
                        {{ Lang::get('counterparties::triage.no_transactions_yet') }}
                    </p>
                @else
                    <ul style="margin: 0; padding: 0; list-style: none;">
                        @foreach ($recentTransactions as $tx)
                            @php
                                // isoFormat('L'), not a substr of the ISO string: this
                                // list sat inside a Dutch page writing 2026-08-13
                                // while every other date in the app read 13-08-2026.
                                // 'L' is the short-date pattern of whatever locale is
                                // rendering, so it follows the reader rather than the
                                // database.
                                $date = is_string($tx->posted_at ?? null) ? CarbonImmutable::parse($tx->posted_at)->isoFormat('L') : '';
                            @endphp
                            <li class="triage-tx">
                                <span class="triage-tx__date">{{ $date }}</span>
                                <span class="triage-tx__desc">{{ $tx->description ?? '' }}</span>
                                <span class="triage-tx__amount">{{ Money::ofMinor(abs((int) ($tx->amount_minor ?? 0)), 'EUR')->format() }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <fieldset class="triage-section" style="border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: var(--space-3);">
                <legend style="font-size: var(--text-xs); text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-text-muted); font-weight: 600; padding: 0 var(--space-1);">
                    {{ Lang::get('counterparties::triage.label_manually') }}
                </legend>
                <div style="display: flex; gap: var(--space-2); flex-wrap: wrap; align-items: center;">
                    <label for="triage-manual-name" class="sr-only">{{ Lang::get('counterparties::triage.display_name_label') }}</label>
                    <input
                        id="triage-manual-name"
                        type="text"
                        placeholder="{{ Lang::get('counterparties::triage.display_name_placeholder') }}"
                        x-data="{ name: '' }"
                        x-model="name"
                        style="flex: 1 1 240px; padding: 6px 10px; border: 1px solid var(--color-border); border-radius: var(--radius-md); font-size: var(--text-sm);"
                    />
                    <label for="triage-manual-type" class="sr-only">{{ Lang::get('counterparties::triage.type_label') }}</label>
                    <select
                        id="triage-manual-type"
                        style="padding: 6px 10px; border: 1px solid var(--color-border); border-radius: var(--radius-md); font-size: var(--text-sm);"
                    >
                        <option value="merchant">{{ Lang::get('counterparties::triage.type_merchant') }}</option>
                        <option value="personal">{{ Lang::get('counterparties::triage.type_personal') }}</option>
                        <option value="bank">{{ Lang::get('counterparties::triage.type_bank') }}</option>
                        <option value="government">{{ Lang::get('counterparties::triage.type_government') }}</option>
                    </select>
                    <button
                        type="button"
                        class="pill-btn-ghost focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                        wire:click="manualLabel(document.getElementById('triage-manual-name').value, document.getElementById('triage-manual-type').value)"
                    >{{ Lang::get('counterparties::triage.save_label') }}</button>
                </div>
            </fieldset>

            <div class="triage-actions" style="justify-content: space-between;">
                <div style="display: flex; gap: var(--space-2);">
                    <button
                        type="button"
                        class="pill-btn-ghost focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                        aria-keyshortcuts="S"
                        wire:click="skipForNow"
                    >↷ {{ Lang::get('counterparties::triage.skip') }}</button>
                    <button
                        type="button"
                        style="background: transparent; border: 0; color: var(--color-rose); font-size: var(--text-sm); cursor: pointer; padding: 4px 10px;"
                        wire:click="markIgnored"
                    >⊘ {{ Lang::get('counterparties::triage.mark_ignored') }}</button>
                </div>
                <div style="display: flex; align-items: center; gap: var(--space-2);">
                    @if ($current !== null)
                        <button
                            type="button"
                            wire:click="previousItem"
                            style="background: transparent; border: 0; color: var(--color-text-muted); font-size: var(--text-sm); cursor: pointer;"
                        >↑ {{ Lang::get('counterparties::triage.previous') }}</button>
                    @endif
                    <button
                        type="button"
                        class="pill-btn-primary focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                        aria-keyshortcuts="ArrowRight"
                        wire:click="nextItem"
                    >{{ Lang::get('counterparties::triage.next') }} ▸</button>
                </div>
            </div>

            {{-- Kbd hint chips: hidden on touch devices per D-13 (pointer:coarse = hidden-touch) --}}
            <p class="hidden-touch" style="font-size: var(--text-xs); color: var(--color-text-muted); margin: 0; text-align: center;">
                <kbd class="kbd">Y</kbd> {{ Lang::get('counterparties::triage.kbd_yes') }} ·
                <kbd class="kbd">N</kbd> {{ Lang::get('counterparties::triage.kbd_no') }} ·
                <kbd class="kbd">S</kbd> {{ Lang::get('counterparties::triage.kbd_skip') }} ·
                <kbd class="kbd">→</kbd> {{ Lang::get('counterparties::triage.kbd_next') }}
            </p>
        </section>

        <footer style="text-align: center; font-size: var(--text-xs); color: var(--color-text-muted); font-variant-numeric: tabular-nums;">
            {{ Lang::get('counterparties::triage.footer', ['seen' => $seen, 'count' => $remainingCount]) }}
        </footer>
    @endif
</div>
