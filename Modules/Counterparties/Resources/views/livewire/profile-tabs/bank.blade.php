{{--
    Bank-type Overview tab body — fee-bar layout per UI-SPEC. Tab bar
    above carries Overview / Entries / Aliases plus the right-of-tab
    note `— bank-fee counterparty doesn't generate funding chains`.

    The fee-bar rows render the per-category fee totals as horizontal
    amber bars. The data feed here uses categoryBreakdown for the
    moment; a richer Bank-fee aggregation lands with Plan 17-06c.
--}}
@use('Modules\Ledger\Public\ValueObjects\Money')
@php
    use Illuminate\Support\Number;
    $maxFee = max(1, ...array_map(fn ($cat) => abs((int) $cat->total_minor), $categoryBreakdown->all() ?: [(object) ['total_minor' => 0]]));
@endphp

<div class="space-y-6" style="margin-top: var(--space-5);">
    <x-counterparties::frame>
        <h3 style="font-size: var(--text-sm); text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-text-muted); font-weight: 600; margin: 0 0 var(--space-3);">
            Bank fees by category
        </h3>
        @if ($categoryBreakdown->isEmpty())
            <p style="font-size: var(--text-sm); color: var(--color-text-muted); margin: 0;">
                No fees recorded on this counterparty yet.
            </p>
        @else
            @foreach ($categoryBreakdown as $cat)
                @php
                    $absMinor = abs((int) $cat->total_minor);
                    $pct = (int) round(($absMinor / $maxFee) * 100);
                @endphp
                <div class="fee-bar-row">
                    <span class="fee-label">{{ $cat->category_name ?? 'Uncategorized' }}</span>
                    <div class="fee-bar-track">
                        <div class="fee-bar-fill" style="width: {{ $pct }}%; background: var(--color-amber);"></div>
                    </div>
                    <span class="fee-total">{{ Number::currency($absMinor / Money::MINOR_UNITS_PER_MAJOR, 'EUR', 'nl') }}</span>
                </div>
            @endforeach
        @endif
    </x-counterparties::frame>

    <x-counterparties::frame>
        <h3 style="font-size: var(--text-sm); text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-text-muted); font-weight: 600; margin: 0 0 var(--space-3);">
            Recent activity
        </h3>
        @include('counterparties::livewire.profile-tabs._recent-activity', ['rows' => $recentActivity, 'taxState' => $taxState ?? []])
    </x-counterparties::frame>
</div>
