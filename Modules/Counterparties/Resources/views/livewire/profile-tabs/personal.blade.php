@use('Modules\Core\Public\Support\Lang')
{{--
    Personal-type Overview tab body — privacy banner + IBAN row with
    Show / Hide toggle + recent transfers. Tab bar above carries
    Overview / Transfers / Aliases.

    Privacy default (load-bearing):
      - The IBAN starts hidden behind the `<x-counterparties::iban-row>`
        Show-IBAN toggle. The full IBAN never appears in the URL, the
        page title, the breadcrumb, or any search result; only inside
        this body, only after the user clicks Show IBAN.

    All copy verbatim per 17-UI-SPEC.md.
--}}
@php
    $ibanLiteral = $profile->iban ?? '';
@endphp

<div class="space-y-6" style="margin-top: var(--space-5);">
    <x-counterparties::privacy-banner />

    <x-counterparties::frame>
        <h3 style="font-size: var(--text-sm); text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-text-muted); font-weight: 600; margin: 0 0 var(--space-3);">
            {{ Lang::get('counterparties::profile.personal.contact') }}
        </h3>
        <x-counterparties::iban-row :iban="$ibanLiteral" :revealed="$ibanRevealed" />
        <div style="margin-top: var(--space-3);">
            <button
                type="button"
                style="padding: 2px 6px; background: transparent; border: 1px dashed var(--color-border-strong); border-radius: var(--radius-full); font-size: var(--text-xs); color: var(--color-text-muted); cursor: pointer;"
            >{{ Lang::get('counterparties::profile.personal.add_tag') }}</button>
        </div>
    </x-counterparties::frame>

    <x-counterparties::frame>
        <h3 style="font-size: var(--text-sm); text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-text-muted); font-weight: 600; margin: 0 0 var(--space-3);">
            {{ Lang::get('counterparties::profile.recurring') }}
        </h3>
        <p style="font-size: var(--text-sm); color: var(--color-text-muted); margin: 0;">
            {{ Lang::get('counterparties::profile.personal.no_recurring') }}
        </p>
    </x-counterparties::frame>

    <x-counterparties::frame>
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: var(--space-3);">
            <h3 style="font-size: var(--text-sm); text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-text-muted); font-weight: 600; margin: 0;">
                {{ Lang::get('counterparties::profile.recent_activity') }}
            </h3>
            <button
                type="button"
                wire:click="switchTab('transfers')"
                class="tap-link"
                style="background: transparent; border: 0; font-size: var(--text-xs); color: var(--color-text); text-decoration: underline; cursor: pointer;"
            >{{ Lang::get('counterparties::profile.see_all', ['count' => $profile->transactionCount]) }}</button>
        </div>
        @include('counterparties::livewire.profile-tabs._recent-activity', ['rows' => $recentActivity, 'taxState' => $taxState ?? []])
    </x-counterparties::frame>
</div>
