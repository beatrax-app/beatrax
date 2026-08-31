@use('Modules\Core\Public\Navigation\Destination')
@use('Modules\Core\Public\Support\Lang')
{{--
    Dashboard tax summary card (UI-SPEC Section 10).

    $total: int|null  — tagged total in minor EUR (null = no data)
    $count: int       — number of tagged items
    $year:  int       — active tax year (0 = unauthenticated)
    $unconvertedList: string — codes left out of $total for want of a rate ('' = none)

    The entire card is an anchor to /tax.
    Height target: 72px (two lines; fits the dashboard grid row).
    Style: existing .card primitive — surface/border/radius-lg/shadow-xs.
    Hover: surface-2 background.
--}}
@use('Modules\Ledger\Public\Services\BaseCurrency')
@use('Modules\Ledger\Public\ValueObjects\Money')

<a
    href="{{ Destination::Tax->url() }}"
    class="card block transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 dark:hover:bg-slate-800"
    style="padding: var(--space-4) var(--space-6); text-decoration: none;"
    aria-label="{{ Lang::choice('tax::summary.card_aria', $count, ['year' => $year]) }}"
>
    <div class="flex items-center justify-between">
        <p style="font-size: var(--text-xs); font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-text-faint);">
            {{ Lang::get('tax::summary.label', ['year' => $year > 0 ? $year : '']) }}
        </p>
        <span style="font-size: var(--text-xs); color: var(--color-text-faint);">→</span>
    </div>

    @if ($count === 0 || $year === 0)
        <p style="margin-top: var(--space-2); font-size: var(--text-base); color: var(--color-text-faint);">
            {{ Lang::get('tax::summary.empty', ['period' => $year > 0 ? $year : Lang::get('tax::summary.this_year')]) }}
        </p>
    @else
        <p
            class="kpi-number"
            style="margin-top: var(--space-1); font-size: var(--text-xl); font-weight: 600; color: var(--color-text);"
        >
            {{ Money::ofMinor($total ?? 0, BaseCurrency::value())->format() }}
        </p>
        <p style="margin-top: 2px; font-size: var(--text-xs); color: var(--color-text-muted);">
            {{ Lang::choice('tax::summary.tagged', $count, ['count' => $count]) }}
        </p>
        @if ($unconvertedList !== '')
            <p data-not-converted="true" style="margin-top: 2px; font-size: var(--text-xs); color: var(--color-text-faint);">
                {{ Lang::get('core::money.not_converted', ['list' => $unconvertedList]) }}
            </p>
        @endif
    @endif
</a>
