@use('Modules\Core\Public\Support\Lang')
{{-- The bars are fed from categoryBreakdown, which is per-category spend
    rather than a bank-fee aggregation — close enough while every row on a
    bank counterparty is a fee. --}}
@use('Modules\Ledger\Public\ValueObjects\Money')
@php
    $maxFee = max(1, ...array_map(fn ($cat) => abs((int) $cat->total_minor), $categoryBreakdown->all() ?: [(object) ['total_minor' => 0]]));
@endphp

<div class="space-y-6" style="margin-top: var(--space-5);">
    <x-counterparties::frame>
        <h3 style="font-size: var(--text-sm); text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-text-muted); font-weight: 600; margin: 0 0 var(--space-3);">
            {{ Lang::get('counterparties::profile.bank.fees_heading') }}
        </h3>
        @if ($categoryBreakdown->isEmpty())
            <p style="font-size: var(--text-sm); color: var(--color-text-muted); margin: 0;">
                {{ Lang::get('counterparties::profile.bank.no_fees') }}
            </p>
        @else
            @foreach ($categoryBreakdown as $cat)
                @php
                    $absMinor = abs((int) $cat->total_minor);
                    $pct = (int) round(($absMinor / $maxFee) * 100);
                @endphp
                <div class="fee-bar-row">
                    <span class="fee-label">{{ $cat->category_name ?? Lang::get('counterparties::profile.uncategorized') }}</span>
                    <div class="fee-bar-track">
                        <div class="fee-bar-fill" style="width: {{ $pct }}%; background: var(--color-amber);"></div>
                    </div>
                    <span class="fee-total">{{ Money::ofMinor($absMinor, 'EUR')->format() }}</span>
                </div>
            @endforeach
        @endif
    </x-counterparties::frame>

    <x-counterparties::frame>
        <h3 style="font-size: var(--text-sm); text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-text-muted); font-weight: 600; margin: 0 0 var(--space-3);">
            {{ Lang::get('counterparties::profile.recent_activity') }}
        </h3>
        @include('counterparties::livewire.profile-tabs._recent-activity', ['rows' => $recentActivity, 'taxState' => $taxState ?? []])
    </x-counterparties::frame>
</div>
