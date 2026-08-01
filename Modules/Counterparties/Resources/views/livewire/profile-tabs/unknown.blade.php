@use('Modules\Core\Public\Support\Lang')
{{--
    Unknown-type Overview tab body — surfaces the prominent
    "Label this counterparty" CTA + the same recent-activity strip the
    other types render. No Chains tab (the tab bar above omits it).
--}}
<div class="space-y-6" style="margin-top: var(--space-5);">
    <x-counterparties::frame style="text-align: center; padding: var(--space-6);">
        <h3 style="font-size: var(--text-base); font-weight: 600; color: var(--color-text); margin: 0 0 var(--space-2);">
            {{ Lang::get('counterparties::profile.unknown.not_labelled_heading') }}
        </h3>
        <p style="font-size: var(--text-sm); color: var(--color-text-muted); margin: 0 0 var(--space-4); max-width: 480px; margin-left: auto; margin-right: auto;">
            {{ Lang::get('counterparties::profile.unknown.not_labelled_body') }}
        </p>
        <a
            href="{{ route('counterparties.triage', ['queue_first' => $profile->id]) }}"
            class="pill-btn-primary focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900"
            style="display: inline-block;"
        >{{ Lang::get('counterparties::profile.unknown.label_cta') }}</a>
    </x-counterparties::frame>

    <x-counterparties::frame>
        <h3 style="font-size: var(--text-sm); text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-text-muted); font-weight: 600; margin: 0 0 var(--space-3);">
            {{ Lang::get('counterparties::profile.recent_activity') }}
        </h3>
        @include('counterparties::livewire.profile-tabs._recent-activity', ['rows' => $recentActivity, 'taxState' => $taxState ?? []])
    </x-counterparties::frame>
</div>
