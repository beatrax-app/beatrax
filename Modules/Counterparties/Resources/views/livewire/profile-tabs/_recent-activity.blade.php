@use('Modules\Core\Public\Support\Lang')
@use('Modules\Core\Public\Support\Fmt')
{{--
    Recent-activity partial reused by every profile-tab body to render
    a uniform list of the counterparty's most-recent transactions.

    Variables:
      $rows      iterable<\stdClass>  — { id, posted_at, description, amount_minor, currency }
      $taxState  array<int, array{taxTagged: bool, taxCategoryShortName: ?string}>  (optional)
--}}
@use('Modules\Ledger\Public\ValueObjects\Money')
@use('Modules\Ledger\Public\Services\BaseCurrency')

@if (count($rows) === 0)
    <p style="font-size: var(--text-sm); color: var(--color-text-muted); margin: 0;">
        {{ Lang::get('counterparties::profile.no_recent_transactions') }}
    </p>
@else
    <ul style="margin: 0; padding: 0; list-style: none;">
        @foreach ($rows as $tx)
            @php
                $date = is_string($tx->posted_at ?? null) && $tx->posted_at !== ''
                    ? Fmt::shortDate($tx->posted_at)
                    : '';
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
                <span style="white-space: nowrap;">{{ Money::ofMinor(abs($amount), $currency)->format() }}</span>
            </li>
        @endforeach
    </ul>
@endif
