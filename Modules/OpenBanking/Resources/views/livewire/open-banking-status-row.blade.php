@use('Modules\Core\Public\Support\Lang')
{{-- Settings entry row (19-11, UI-SPEC Surface A) — Aliases link-out shape. --}}
<div class="space-y-2" data-testid="open-banking-status-row">
    <h2 class="text-xs uppercase tracking-wide text-[var(--color-text-faint)]">{{ Lang::get('openbanking::messages.status_row.heading') }}</h2>
    <p class="text-sm {{ $expired ? 'text-rose-600 dark:text-rose-400' : 'text-slate-500 dark:text-slate-400' }}" data-testid="ob-status-text">
        {{ $statusText }}
    </p>
    <a
        href="{{ route('settings.open-banking') }}"
        class="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:hover:bg-slate-800"
    >{{ Lang::get('openbanking::messages.status_row.manage') }} &rarr;</a>
</div>
