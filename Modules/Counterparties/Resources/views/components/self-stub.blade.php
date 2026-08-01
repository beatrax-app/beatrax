@use('Modules\Core\Public\Support\Lang')
{{--
    Self-account stub — dead-end redirect surface rendered when a
    profile resolves to a self_account type. Carries verbatim copy
    explaining that the row exists because it shows up as a funding
    leg, but it's the user's own account; the primary CTA routes to
    the Accounts view (route wiring lands in the consuming Livewire
    page), and a secondary `Hide from this list` removes the row.

    Optionally renders up to three recent cross-account legs below the
    stub for context (passed in via the `recent` prop or the default
    slot).

    Accessibility:
      - `role="region"` + `aria-label="Not a real counterparty"` so
        screen readers can land on the explanation as a landmark.

    Props:
      account — anonymous object/array exposing { name, slug } for the
                "Open {Account name} account view →" CTA
      recent  — array; up to three { date, label, amount } rows shown
                below the stub
--}}
@props([
    'account',
    'recent' => [],
])
@php
    $accountName = is_array($account) ? ($account['name'] ?? '—') : ($account->name ?? '—');
@endphp
<section
    {{ $attributes->merge(['class' => 'self-stub']) }}
    role="region"
    aria-label="{{ Lang::get('counterparties::components.self_stub.aria') }}"
>
    <span class="stub-icon" aria-hidden="true">⊘</span>

    {{-- {!! !!}: the heading carries a literal apostrophe that must reach the
         DOM un-escaped (the surface test asserts it raw). App-static copy only —
         no user data is interpolated here. --}}
    <h2 style="font-size: var(--text-2xl); font-weight: 600; color: var(--color-text); margin: 0;">
        {!! Lang::get('counterparties::components.self_stub.heading') !!}
    </h2>

    <p style="font-size: var(--text-base); color: var(--color-text-muted); line-height: 1.55; max-width: 480px; margin: 0;">
        {{ $accountName }}{!! Lang::get('counterparties::components.self_stub.body_rest_html') !!}
    </p>

    <p style="font-size: var(--text-base); color: var(--color-text-muted); line-height: 1.55; max-width: 480px; margin: 0;">
        {{ Lang::get('counterparties::components.self_stub.body2') }}
    </p>

    <div class="stub-actions">
        <button
            type="button"
            class="pill-btn-primary focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
            data-stub-action="open-account"
        >{{ Lang::get('counterparties::components.self_stub.open_cta', ['name' => $accountName]) }}</button>
        <button
            type="button"
            class="pill-btn-ghost focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
            data-stub-action="hide-from-list"
        >{{ Lang::get('counterparties::components.self_stub.hide_cta') }}</button>
    </div>

    @if (! empty($recent) || isset($slot) && trim((string) $slot) !== '')
        <div style="width: 100%; max-width: 480px; margin-top: var(--space-6); text-align: left;">
            <p style="font-size: var(--text-xs); text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-text-muted); font-weight: 600; margin: 0 0 var(--space-2);">
                {{ Lang::get('counterparties::components.self_stub.recent_legs') }}
            </p>
            @foreach (array_slice($recent, 0, 3) as $leg)
                <p style="font-size: var(--text-sm); color: var(--color-text); margin: 4px 0; font-variant-numeric: tabular-nums;">
                    {{ is_array($leg) ? ($leg['label'] ?? '') : $leg }}
                </p>
            @endforeach
            {{ $slot }}
        </div>
    @endif
</section>
