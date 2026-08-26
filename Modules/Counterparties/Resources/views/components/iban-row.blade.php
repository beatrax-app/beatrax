@use('Modules\Core\Public\Support\Iban')
@use('Modules\Core\Public\Support\Lang')
{{--
    IBAN reveal row — the personal-profile IBAN line with a
    Show/Hide toggle. Default state is hidden (dotted placeholder)
    per the privacy contract; the user explicitly opts in by clicking
    `Show IBAN`. The reveal is page-local — Alpine state lives in the
    component's `x-data` and resets on page navigation.

    Accessibility:
      - hidden display carries an `aria-label` describing the toggle path
      - revealed IBAN gets `aria-live="polite"` so screen readers announce
        the disclosure on the next AT polling cycle

    Props:
      iban     — string; the full IBAN to reveal when the user opts in
      revealed — bool; initial reveal state (default false)
--}}
@props([
    'iban' => '',
    'revealed' => false,
])
<div
    {{ $attributes->merge(['class' => 'iban-row']) }}
    x-data="{ revealed: {{ $revealed ? 'true' : 'false' }} }"
>
    <span class="iban-label">{{ Lang::get('counterparties::components.iban_row.label') }}</span>
    <span
        role="img"
        class="iban-hidden"
        x-show="!revealed"
        aria-label="{{ Lang::get('counterparties::components.iban_row.hidden_aria') }}"
    >····  ····  ····  ····</span>
    <span
        class="iban-revealed"
        x-show="revealed"
        x-cloak
        aria-live="polite"
    >{{ Iban::grouped($iban) }}</span>
    <button
        type="button"
        class="focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
        x-on:click="revealed = !revealed"
        x-text="revealed ? '{{ Lang::get('counterparties::components.iban_row.hide') }}' : '{{ Lang::get('counterparties::components.iban_row.show') }}'"
        style="background: transparent; border: 0; color: var(--color-text-muted); font-size: var(--text-sm); cursor: pointer; padding: 2px 6px;"
    >{{ Lang::get('counterparties::components.iban_row.show') }}</button>
</div>
