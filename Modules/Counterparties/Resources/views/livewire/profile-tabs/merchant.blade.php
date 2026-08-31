@use('Modules\Core\Public\Support\Lang')
{{--
    Merchant-type Overview tab body — Categories, Recurring, Funding
    chain summary, Recent activity. Tab bar above (rendered in the
    shell) carries Overview / Transactions / Chains / Aliases.

    All copy verbatim per 17-UI-SPEC.md.

    Variables (inherited from the profile shell include):
      $profile           CounterpartyProfileDto
      $recentActivity    Collection<\stdClass>
      $categoryBreakdown Collection<\stdClass>
      $fundingChain      ChainSummary|null
--}}
@use('Modules\Ledger\Public\ValueObjects\Money')

<div class="space-y-6" style="margin-top: var(--space-5);">
    <div class="cp-tab-duo">
        {{-- Categories -------------------------------------------- --}}
        <x-counterparties::frame>
            <h3 style="font-size: var(--text-sm); text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-text-muted); font-weight: 600; margin: 0 0 var(--space-3);">
                {{ Lang::get('counterparties::profile.merchant.categories') }}
            </h3>
            @if ($categoryBreakdown->isEmpty())
                <p style="font-size: var(--text-sm); color: var(--color-text-muted); margin: 0;">
                    {!! Lang::get('counterparties::profile.merchant.categories_empty_html') !!}
                </p>
            @else
                <ul style="margin: 0; padding: 0; list-style: none;">
                    @foreach ($categoryBreakdown as $cat)
                        <li style="display: flex; flex-wrap: wrap; justify-content: space-between; padding: var(--space-1) 0; font-size: var(--text-sm); font-variant-numeric: tabular-nums;">
                            <span>{{ $cat->category_name ?? Lang::get('counterparties::profile.uncategorized') }}</span>
                            <span>{{ Money::ofMinor(abs((int) $cat->total_minor), $cat->currency)->format() }}</span>
                            @if ($cat->unconverted !== [])
                                <span style="flex-basis: 100%; font-size: var(--text-xs); color: var(--color-text-faint);" data-not-converted="true">{{ Lang::get('core::money.not_converted', ['list' => implode(', ', $cat->unconverted)]) }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-counterparties::frame>

        {{-- Recurring --------------------------------------------- --}}
        <x-counterparties::frame>
            <h3 style="font-size: var(--text-sm); text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-text-muted); font-weight: 600; margin: 0 0 var(--space-3);">
                {{ Lang::get('counterparties::profile.recurring') }}
            </h3>
            @if (count($recurringSeries ?? []) === 0)
                <p style="font-size: var(--text-sm); color: var(--color-text-muted); margin: 0;">
                    {{ Lang::get('counterparties::profile.merchant.no_recurring') }}
                </p>
            @else
                <ul style="margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: var(--space-2);">
                    @foreach ($recurringSeries as $series)
                        <li style="display: flex; align-items: baseline; justify-content: space-between; gap: var(--space-3);">
                            <a
                                class="merchant-series-link"
                                href="{{ route('recurring.series.show', ['seriesId' => $series->seriesId]) }}"
                                style="font-size: var(--text-sm); color: var(--color-text); text-decoration: underline; text-underline-offset: 2px;"
                            >{{ $series->displayName() }}</a>
                            <span style="font-size: var(--text-sm); color: var(--color-text-muted); font-variant-numeric: tabular-nums; white-space: nowrap;">
                                {{ $series->monthlyEquivalent->format() }}{{ Lang::get('counterparties::profile.merchant.per_month_suffix') }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-counterparties::frame>
    </div>

    {{-- Funding chain ----------------------------------------- --}}
    <x-counterparties::frame>
        <h3 style="font-size: var(--text-sm); text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-text-muted); font-weight: 600; margin: 0 0 var(--space-3);">
            {{ Lang::get('counterparties::profile.merchant.funding_chain') }}
        </h3>
        @if ($fundingChain === null)
            <p style="font-size: var(--text-sm); color: var(--color-text-muted); margin: 0;">
                {{ Lang::get('counterparties::profile.merchant.no_funding_chain') }}
            </p>
            <p style="margin: var(--space-2) 0 0;">
                <a class="tap-link" href="/chains/review" style="font-size: var(--text-sm); color: var(--color-text); text-decoration: underline;">{{ Lang::get('counterparties::profile.merchant.open_chains') }}</a>
            </p>
        @else
            <x-counterparties::chain-flow :nodes="$fundingChain->nodes" />
        @endif
    </x-counterparties::frame>

    {{-- Recent activity --------------------------------------- --}}
    <x-counterparties::frame>
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: var(--space-3);">
            <h3 style="font-size: var(--text-sm); text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-text-muted); font-weight: 600; margin: 0;">
                {{ Lang::get('counterparties::profile.recent_activity') }}
            </h3>
            <button
                type="button"
                wire:click="switchTab('transactions')"
                class="tap-link"
                style="background: transparent; border: 0; font-size: var(--text-xs); color: var(--color-text); text-decoration: underline; cursor: pointer;"
            >{{ Lang::get('counterparties::profile.see_all', ['count' => $profile->transactionCount]) }}</button>
        </div>
        @include('counterparties::livewire.profile-tabs._recent-activity', ['rows' => $recentActivity, 'taxState' => $taxState ?? []])
    </x-counterparties::frame>
</div>
