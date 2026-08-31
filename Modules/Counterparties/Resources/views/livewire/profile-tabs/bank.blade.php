@use('Modules\Core\Public\Support\Lang')
@use('Modules\Ledger\Public\ValueObjects\Money')
{{-- Two chain steps write type='bank': the bank-fee corpus, whose rows really
    are charges the bank levies, and the known-IBAN bridge, whose rows are an
    institution the reader buys THROUGH. Calling a PayPal settlement a bank fee
    is the panel claiming something about the money that is not true. --}}
@php
    $isFee = $profile->isBankFee;
    $maxBar = max(1, ...array_map(fn ($cat) => abs((int) $cat->total_minor), $categoryBreakdown->all() ?: [(object) ['total_minor' => 0]]));
@endphp

<div class="space-y-6" style="margin-top: var(--space-5);">
    <x-counterparties::frame>
        <h3 style="font-size: var(--text-sm); text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-text-muted); font-weight: 600; margin: 0 0 var(--space-3);">
            {{ $isFee ? Lang::get('counterparties::profile.bank.fees_heading') : Lang::get('counterparties::profile.bank.activity_heading') }}
        </h3>
        @if ($categoryBreakdown->isEmpty())
            <p style="font-size: var(--text-sm); color: var(--color-text-muted); margin: 0;">
                {{ $isFee ? Lang::get('counterparties::profile.bank.no_fees') : Lang::get('counterparties::profile.no_recent_transactions') }}
            </p>
        @else
            @foreach ($categoryBreakdown as $cat)
                @php
                    $absMinor = abs((int) $cat->total_minor);
                    $pct = (int) round(($absMinor / $maxBar) * 100);
                @endphp
                <div class="fee-bar-row">
                    <span class="fee-label">{{ $cat->category_name ?? Lang::get('counterparties::profile.uncategorized') }}</span>
                    <div class="fee-bar-track">
                        <div class="fee-bar-fill" style="width: {{ $pct }}%; background: {{ $isFee ? 'var(--color-amber)' : 'var(--color-text-muted)' }};"></div>
                    </div>
                    <span class="fee-total">{{ Money::ofMinor($absMinor, $cat->currency)->format() }}</span>
                </div>
                @if ($cat->unconverted !== [])
                    <p style="font-size: var(--text-xs); color: var(--color-text-faint); margin: 0 0 var(--space-2);" data-not-converted="true">{{ Lang::get('core::money.not_converted', ['list' => implode(', ', $cat->unconverted)]) }}</p>
                @endif
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
