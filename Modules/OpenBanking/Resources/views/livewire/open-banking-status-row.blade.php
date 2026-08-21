@use('Modules\Core\Public\Support\Lang')
{{-- Settings entry row (19-11, UI-SPEC Surface A) — Aliases link-out shape. --}}
<div class="space-y-2" data-testid="open-banking-status-row">
    <h2 class="text-xs uppercase tracking-wide text-[var(--color-text-faint)]">{{ Lang::get('openbanking::messages.status_row.heading') }}</h2>
    <p class="text-sm {{ $expired ? 'text-rose-600 dark:text-rose-400' : 'text-slate-500 dark:text-slate-400' }}" data-testid="ob-status-text">
        {{ $statusText }}
    </p>
    <x-core::secondary-button
        :href="route('settings.open-banking')"
        size="sm"
    >{{ Lang::get('openbanking::messages.status_row.manage') }} &rarr;</x-core::secondary-button>
</div>
