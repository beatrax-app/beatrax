{{--
    Recent-activity partial reused by every profile-tab body to render
    a uniform list of the counterparty's most-recent transactions.

    Variables:
      $rows      iterable<\stdClass>  — { id, posted_at, description, amount_minor, currency }
      $taxState  array<int, array{taxTagged: bool, taxCategoryShortName: ?string}>  (optional)
--}}
@use('Modules\Ledger\Public\ValueObjects\Money')
@use('Modules\Ledger\Public\Services\BaseCurrency')
@php
    use Illuminate\Support\Number;
@endphp

@if (count($rows) === 0)
    <p style="font-size: var(--text-sm); color: var(--color-text-muted); margin: 0;">
        No transactions on file for this counterparty yet.
    </p>
@else
    <ul style="margin: 0; padding: 0; list-style: none;">
        @foreach ($rows as $tx)
            @php
                $date = is_string($tx->posted_at ?? null) ? substr($tx->posted_at, 0, 10) : '';
                $desc = $tx->description ?? '';
                $amount = (int) ($tx->amount_minor ?? 0);
                $currency = $tx->currency ?? BaseCurrency::value();
            @endphp
            @php
                $txId = (int) ($tx->id ?? 0);
                $txTaxState = isset($taxState[$txId]) ? $taxState[$txId] : ['taxTagged' => false, 'taxCategoryShortName' => null];
                $txRowArr = ['id' => $txId, 'taxTagged' => $txTaxState['taxTagged'], 'taxCategoryShortName' => $txTaxState['taxCategoryShortName']];
            @endphp
            {{-- .group enables the desktop hover-reveal of the untagged tax badge (CR-04). --}}
            <li class="group" style="display: flex; align-items: center; gap: var(--space-3); padding: var(--space-2) 0; border-bottom: 1px solid var(--color-border); font-size: var(--text-sm); font-variant-numeric: tabular-nums;">
                <span style="color: var(--color-text-muted); white-space: nowrap;">{{ $date }}</span>
                <span style="flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis;">{{ $desc }}</span>
                {{-- Tax badge on counterparty transaction rows (D-19/D-20). --}}
                <x-tax::tax-badge :transaction="$txRowArr" :showAlways="false" />
                <span style="white-space: nowrap;">{{ Number::currency(abs($amount) / Money::MINOR_UNITS_PER_MAJOR, $currency, 'nl') }}</span>
            </li>
        @endforeach
    </ul>
@endif
