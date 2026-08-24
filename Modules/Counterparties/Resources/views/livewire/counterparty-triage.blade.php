@use('Modules\Core\Public\Navigation\Destination')
@use('Modules\Core\Public\Support\Lang')
@use('Modules\Core\Public\Support\Fmt')
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
@use('Modules\Counterparties\Public\Enums\CounterpartyType')
@use('Modules\Ledger\Public\Enums\Currency')
@use('Modules\Ledger\Public\Services\CounterpartyKey')
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
        $clean = CounterpartyKey::compactIban($iban);
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
    x-on:keydown.window.escape="if (!inputFocused) window.location.href = '{{ Destination::Counterparties->url() }}'"
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
        <div
            class="progress-bar"
            role="progressbar"
            aria-valuenow="{{ $percent }}"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-label="{{ Lang::get('counterparties::triage.progress_aria') }}"
        >
            <div class="progress-fill" style="width: {{ $percent }}%;"></div>
        </div>
    @endif

    @if ($queueEmpty)
        <section class="triage-card" aria-label="{{ Lang::get('counterparties::triage.all_caught_aria') }}">
            <h2 style="font-size: var(--text-xl); font-weight: 600; color: var(--color-text); margin: 0;">
                {{ Lang::get('counterparties::triage.all_caught_heading') }}
            </h2>
            <p style="margin: 0;">
                <a href="{{ Destination::Counterparties->url() }}" style="font-size: var(--text-sm); color: var(--color-text); text-decoration: underline;">{{ Lang::get('counterparties::triage.back_to_index') }}</a>
            </p>
        </section>
    @else
        @php
            // A PayPal or card counterparty is keyed on its name; there is no
            // account number to key on. The mask answered an em dash for it,
            // so the screen asking the reader to identify a counterparty was
            // the one screen not naming it.
            $hasIban = is_string($current->iban) && $current->iban !== '';
        @endphp
        <section class="triage-card">
            <header class="triage-head">
                <span class="triage-iban">{{ $hasIban ? $maskIban($current->iban) : $current->display_name }}</span>
                <x-counterparties::type-chip type="unknown" />
            </header>

            <p class="triage-meta">
                {{ Lang::choice('counterparties::triage.meta', count($recentTransactions), [
                    'count' => count($recentTransactions),
                    'date' => ! empty($recentTransactions) ? Fmt::shortDate((string) $recentTransactions[0]->posted_at) : '—',
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
                {{-- h2, not h3: the only h2 on this page lives in the
                     all-caught-up card, which is the other arm of the same
                     @if, so with work left in the queue this followed the
                     page h1 directly. Its size is set here, so the level
                     carries no appearance. --}}
                <h2 style="font-size: var(--text-xs); text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-text-muted); font-weight: 600; margin: 0;">
                    {{ Lang::get($hasIban ? 'counterparties::triage.recent_on_iban' : 'counterparties::triage.recent_on_counterparty') }}
                </h2>
                @if (count($recentTransactions) === 0)
                    <p style="font-size: var(--text-sm); color: var(--color-text-muted); margin: 0;">
                        {{ Lang::get('counterparties::triage.no_transactions_yet') }}
                    </p>
                @else
                    <ul style="margin: 0; padding: 0; list-style: none;">
                        @foreach ($recentTransactions as $tx)
                            @php
                                // Fmt, not a substr of the ISO string: this list sat
                                // inside a Dutch page writing 2026-08-13 while every
                                // other date in the app read 13-08-2026. Fmt follows
                                // the reader's locale, day-first in all of them.
                                $date = is_string($tx->posted_at ?? null) ? Fmt::shortDate($tx->posted_at) : '';

                                // Signed, unlike the 12-month totals on the index and profile
                                // pages: this is one transaction, not an aggregate, and an abs()
                                // made a charge and a refund of the same size read identically
                                // on the one screen whose question direction most helps answer.
                                $amountMinor = (int) ($tx->settled_amount_minor ?? 0);
                                $currency = is_string($tx->settled_currency ?? null) && $tx->settled_currency !== ''
                                    ? $tx->settled_currency
                                    : Currency::Eur->value;
                            @endphp
                            <li class="triage-tx">
                                <span class="triage-tx__date">{{ $date }}</span>
                                <span class="triage-tx__desc">{{ $tx->description ?? '' }}</span>
                                <span class="triage-tx__amount">{{ Money::ofMinor($amountMinor, $currency)->format() }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <fieldset class="triage-section" style="border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: var(--space-3);">
                <legend style="font-size: var(--text-xs); text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-text-muted); font-weight: 600; padding: 0 var(--space-1);">
                    {{ Lang::get('counterparties::triage.label_manually') }}
                </legend>
                {{-- One Alpine scope over both fields and the button. The
                     x-data used to sit on the input alone, so the button could
                     not see it and the wire:click reached into the DOM instead
                     — and Livewire evaluates a wire:click against the $wire
                     proxy, where a bare `document` is $wire.document. Every
                     click threw before manualLabel was reached, silently. --}}
                {{-- Keyed to the counterparty: without it Livewire morphs the
                     element in place, Alpine keeps its state, and the name
                     typed for one counterparty was still in the box for the
                     next one. --}}
                <div wire:key="triage-manual-{{ $current?->id ?? 'none' }}" x-data="{ manualName: '', manualType: '{{ CounterpartyType::Merchant->value }}' }" style="display: flex; gap: var(--space-2); flex-wrap: wrap; align-items: center;">
                    <label for="triage-manual-name" class="sr-only">{{ Lang::get('counterparties::triage.display_name_label') }}</label>
                    <input
                        id="triage-manual-name"
                        type="text"
                        placeholder="{{ Lang::get('counterparties::triage.display_name_placeholder') }}"
                        x-model="manualName"
                        style="flex: 1 1 240px; min-width: 0; padding: 6px 10px; border: 1px solid var(--color-border); border-radius: var(--radius-md); font-size: 16px;"
                    />
                    <label for="triage-manual-type" class="sr-only">{{ Lang::get('counterparties::triage.type_label') }}</label>
                    <select
                        id="triage-manual-type"
                        x-model="manualType"
                        style="padding: 6px 10px; border: 1px solid var(--color-border); border-radius: var(--radius-md); font-size: 16px;"
                    >
                        <option value="{{ CounterpartyType::Merchant->value }}">{{ Lang::get('counterparties::triage.type_merchant') }}</option>
                        <option value="{{ CounterpartyType::Personal->value }}">{{ Lang::get('counterparties::triage.type_personal') }}</option>
                        <option value="{{ CounterpartyType::Bank->value }}">{{ Lang::get('counterparties::triage.type_bank') }}</option>
                        <option value="{{ CounterpartyType::Government->value }}">{{ Lang::get('counterparties::triage.type_government') }}</option>
                    </select>
                    <button
                        type="button"
                        class="pill-btn-ghost focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                        x-on:click="$wire.manualLabel(manualName, manualType); manualName = ''"
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

            {{-- Kbd hint chips: hidden on touch devices (pointer:coarse = hidden-touch) --}}
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
