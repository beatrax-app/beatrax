{{--
    Recent-activity partial reused by every profile-tab body to render
    a uniform list of the counterparty's most-recent transactions.

    Variables:
      $rows  iterable<\stdClass>  — { id, posted_at, description, amount_minor, currency }
--}}
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
                $currency = $tx->currency ?? 'EUR';
            @endphp
            <li style="display: flex; align-items: baseline; gap: var(--space-3); padding: var(--space-2) 0; border-bottom: 1px solid var(--color-border); font-size: var(--text-sm); font-variant-numeric: tabular-nums;">
                <span style="color: var(--color-text-muted); white-space: nowrap;">{{ $date }}</span>
                <span style="flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis;">{{ $desc }}</span>
                <span style="white-space: nowrap;">{{ Number::currency(abs($amount) / 100, $currency, 'nl') }}</span>
            </li>
        @endforeach
    </ul>
@endif
