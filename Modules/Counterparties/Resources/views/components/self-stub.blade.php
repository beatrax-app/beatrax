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
    aria-label="Not a real counterparty"
>
    <span class="stub-icon" aria-hidden="true">⊘</span>

    <h2 style="font-size: var(--text-2xl); font-weight: 600; color: var(--color-text); margin: 0;">
        This isn't really a counterparty
    </h2>

    <p style="font-size: var(--text-base); color: var(--color-text-muted); line-height: 1.55; max-width: 480px; margin: 0;">
        {{ $accountName }} appears here because it shows up in your transactions as the funding leg between accounts. But it's <strong>your own account</strong>, not someone you transact with.
    </p>

    <p style="font-size: var(--text-base); color: var(--color-text-muted); line-height: 1.55; max-width: 480px; margin: 0;">
        Open the account view for balance, statements, and full transaction history.
    </p>

    <div class="stub-actions">
        <button
            type="button"
            class="pill-btn-primary focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
            data-stub-action="open-account"
        >Open {{ $accountName }} account view →</button>
        <button
            type="button"
            class="pill-btn-ghost focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
            data-stub-action="hide-from-list"
        >Hide from this list</button>
    </div>

    @if (! empty($recent) || isset($slot) && trim((string) $slot) !== '')
        <div style="width: 100%; max-width: 480px; margin-top: var(--space-6); text-align: left;">
            <p style="font-size: var(--text-xs); text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-text-muted); font-weight: 600; margin: 0 0 var(--space-2);">
                Recent cross-account legs
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
