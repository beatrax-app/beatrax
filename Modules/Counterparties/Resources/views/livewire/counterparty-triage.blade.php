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
@php
    use Illuminate\Support\Number;

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
            Triage unknown counterparties
        </h1>
        <span style="font-size: var(--text-sm); color: var(--color-text-muted); font-variant-numeric: tabular-nums;">
            {{ $seen }} of {{ $total }} · {{ $percent }} % · ~{{ $minutesRemaining }} min remaining
        </span>
    </header>

    <div class="progress-bar" aria-label="Triage progress">
        <div class="progress-fill" style="width: {{ $percent }}%;"></div>
    </div>

    @if ($queueEmpty)
        <section class="triage-card" aria-label="All counterparties labeled">
            <h2 style="font-size: var(--text-xl); font-weight: 600; color: var(--color-text); margin: 0;">
                🎉 All caught up — every counterparty is labeled.
            </h2>
            <p style="margin: 0;">
                <a href="{{ route('counterparties.index') }}" style="font-size: var(--text-sm); color: var(--color-text); text-decoration: underline;">Back to counterparties →</a>
            </p>
        </section>
    @else
        <section class="triage-card">
            <header class="triage-head">
                <span class="triage-iban">{{ $maskIban($current->iban) }}</span>
                <x-counterparties::type-chip type="unknown" />
            </header>

            <p class="triage-meta">
                {{ count($recentTransactions) }} transactions · last seen
                {{ ! empty($recentTransactions) ? substr((string) $recentTransactions[0]->posted_at, 0, 10) : '—' }}
            </p>

            @if ($showSuggestion && $suggestion !== null)
                @php
                    $bannerClass = match ($suggestion->confidence) {
                        'medium' => 'suggestion medium',
                        'low' => 'suggestion low',
                        default => 'suggestion',
                    };
                    $bannerCopy = match ($suggestion->confidence) {
                        'medium' => '✨ Maybe **'.$suggestion->suggestedCounterpartyName.'** — confidence medium',
                        'low' => 'Pattern match: **'.$suggestion->suggestedCounterpartyName.'** — confidence low. Verify before linking.',
                        default => '✨ Looks like **'.$suggestion->suggestedCounterpartyName.'** — confidence high',
                    };
                @endphp
                <section class="{{ $bannerClass }}" aria-label="Suggested match">
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
                    >Yes, link to {{ $suggestion->suggestedCounterpartyName }} ↵</button>
                    <button
                        type="button"
                        class="pill-btn-ghost focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                        aria-keyshortcuts="N"
                        wire:click="rejectSuggestion"
                    >No, not {{ $suggestion->suggestedCounterpartyName }}</button>
                </div>
            @endif

            <div class="triage-section">
                <h3 style="font-size: var(--text-xs); text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-text-muted); font-weight: 600; margin: 0;">
                    Recent transactions on this IBAN
                </h3>
                @if (count($recentTransactions) === 0)
                    <p style="font-size: var(--text-sm); color: var(--color-text-muted); margin: 0;">
                        No transactions on file yet.
                    </p>
                @else
                    <ul style="margin: 0; padding: 0; list-style: none;">
                        @foreach ($recentTransactions as $tx)
                            @php
                                $date = is_string($tx->posted_at ?? null) ? substr($tx->posted_at, 0, 10) : '';
                            @endphp
                            <li style="display: flex; gap: var(--space-3); padding: var(--space-1) 0; font-size: var(--text-sm); font-variant-numeric: tabular-nums;">
                                <span style="color: var(--color-text-muted); white-space: nowrap;">{{ $date }}</span>
                                <span style="flex: 1 1 auto;">{{ $tx->description ?? '' }}</span>
                                <span>{{ Number::currency(abs((int) ($tx->amount_minor ?? 0)) / 100, 'EUR', 'nl') }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <fieldset class="triage-section" style="border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: var(--space-3);">
                <legend style="font-size: var(--text-xs); text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-text-muted); font-weight: 600; padding: 0 var(--space-1);">
                    Or label manually
                </legend>
                <div style="display: flex; gap: var(--space-2); flex-wrap: wrap; align-items: center;">
                    <label for="triage-manual-name" class="sr-only">Display name</label>
                    <input
                        id="triage-manual-name"
                        type="text"
                        placeholder="Display name…"
                        x-data="{ name: '' }"
                        x-model="name"
                        style="flex: 1 1 240px; padding: 6px 10px; border: 1px solid var(--color-border); border-radius: var(--radius-md); font-size: var(--text-sm);"
                    />
                    <label for="triage-manual-type" class="sr-only">Type</label>
                    <select
                        id="triage-manual-type"
                        style="padding: 6px 10px; border: 1px solid var(--color-border); border-radius: var(--radius-md); font-size: var(--text-sm);"
                    >
                        <option value="merchant">Merchant</option>
                        <option value="personal">Personal</option>
                        <option value="bank">Bank</option>
                        <option value="government">Government</option>
                    </select>
                    <button
                        type="button"
                        class="pill-btn-ghost focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                        wire:click="manualLabel(document.getElementById('triage-manual-name').value, document.getElementById('triage-manual-type').value)"
                    >Save label</button>
                </div>
            </fieldset>

            <div class="triage-actions" style="justify-content: space-between;">
                <div style="display: flex; gap: var(--space-2);">
                    <button
                        type="button"
                        class="pill-btn-ghost focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                        aria-keyshortcuts="S"
                        wire:click="skipForNow"
                    >↷ Skip for now</button>
                    <button
                        type="button"
                        style="background: transparent; border: 0; color: var(--color-rose); font-size: var(--text-sm); cursor: pointer; padding: 4px 10px;"
                        wire:click="markIgnored"
                    >⊘ Mark as ignored</button>
                </div>
                <div style="display: flex; align-items: center; gap: var(--space-2);">
                    @if ($current !== null)
                        <button
                            type="button"
                            wire:click="previousItem"
                            style="background: transparent; border: 0; color: var(--color-text-muted); font-size: var(--text-sm); cursor: pointer;"
                        >↑ Previous unknown</button>
                    @endif
                    <button
                        type="button"
                        class="pill-btn-primary focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
                        aria-keyshortcuts="ArrowRight"
                        wire:click="nextItem"
                    >Next ▸</button>
                </div>
            </div>

            {{-- Kbd hint chips: hidden on touch devices per D-13 (pointer:coarse = hidden-touch) --}}
            <p class="hidden-touch" style="font-size: var(--text-xs); color: var(--color-text-muted); margin: 0; text-align: center;">
                <kbd class="kbd">Y</kbd> yes ·
                <kbd class="kbd">N</kbd> no ·
                <kbd class="kbd">S</kbd> skip ·
                <kbd class="kbd">→</kbd> next
            </p>
        </section>

        <footer style="text-align: center; font-size: var(--text-xs); color: var(--color-text-muted); font-variant-numeric: tabular-nums;">
            {{ $seen }} already labeled · {{ $remainingCount }} to go
        </footer>
    @endif
</div>
