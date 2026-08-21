@use('Modules\Core\Public\Support\Lang')
{{--
    Government-type Overview tab body — full-width 3-up tax-year cards
    with the current year emphasized. Tab bar above carries
    Overview / Payments / Tax years / Aliases plus the right-of-tab
    note `— no funding chains for government counterparties`.

    Variables:
      $taxYears  Collection<\stdClass{ year: int, total_minor: int }>
      $profile   CounterpartyProfileDto
--}}
@use('Modules\Ledger\Public\Services\BaseCurrency')
@use('Modules\Ledger\Public\ValueObjects\Money')
@php
    $currentYear = (int) now()->format('Y');
@endphp

<div class="space-y-6" style="margin-top: var(--space-5);">
    <p style="font-size: var(--text-sm); color: var(--color-text-muted); margin: 0;">
        {{ Lang::get('counterparties::profile.government.intro') }}
    </p>

    @if ($taxYears->isEmpty())
        <p style="font-size: var(--text-sm); color: var(--color-text-muted); margin: 0;">
            {{ Lang::get('counterparties::profile.government.no_payments') }}
        </p>
    @else
        <div class="tax-year-row">
            @foreach ($taxYears->take(3) as $year)
                <article class="tax-year-card {{ (int) $year->year === $currentYear ? 'current' : '' }}">
                    <span style="font-size: var(--text-xs); text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-text-muted); font-weight: 600;">
                        {{ (int) $year->year }}
                    </span>
                    <span style="font-size: var(--text-2xl); font-weight: 600; color: var(--color-text); font-variant-numeric: tabular-nums;">
                        {{ Money::ofMinor(abs((int) $year->total_minor), BaseCurrency::value())->format() }}
                    </span>
                </article>
            @endforeach
        </div>
    @endif

    <x-counterparties::frame>
        <h3 style="font-size: var(--text-sm); text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-text-muted); font-weight: 600; margin: 0 0 var(--space-3);">
            {{ Lang::get('counterparties::profile.recent_activity') }}
        </h3>
        @include('counterparties::livewire.profile-tabs._recent-activity', ['rows' => $recentActivity, 'taxState' => $taxState ?? []])
    </x-counterparties::frame>
</div>
