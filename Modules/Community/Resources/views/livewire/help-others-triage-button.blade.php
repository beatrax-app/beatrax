@use('Modules\Core\Public\Support\Lang')
{{-- Help others identify this — triage row CTA.

     Renders only when the user's
     `community_settings.offerToContribute` toggle is true. Empty
     wrapping div when off so the parent triage row's grid stays
     structurally stable regardless of the toggle state. --}}

<div>
    @if ($visible)
        <button
            type="button"
            wire:click="clicked"
            class="help-others-link inline-flex items-center rounded-md border border-dashed border-slate-300 px-2 py-1 text-xs text-slate-500 hover:border-slate-400 hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-600 dark:text-slate-400 dark:hover:border-slate-500 dark:hover:text-slate-200"
            data-testid="help-others-cta"
        >{{ Lang::get('community::triage.cta') }}</button>
    @endif
</div>
