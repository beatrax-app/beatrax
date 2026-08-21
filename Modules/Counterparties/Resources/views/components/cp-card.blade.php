@use('Modules\Core\Public\Support\Lang')
@use('Modules\Counterparties\Public\Enums\CounterpartyType')
{{--
    Counterparty card — the grid item on the `/counterparties` cards
    view. Surfaces the counterparty's name, type chip, headline stat
    (12-month total or Net received for personal), 12-month sparkline,
    and up to two recent activity lines.

    Unknown counterparties render in the dashed-border `.unknown`
    variant and surface the "❋ Label this counterparty" CTA inline at
    the foot of the card, routing to `/counterparties/triage` with the
    unknown queued first (route wiring lands in the consuming Livewire
    page).

    Slot content overrides the default body so consumers can wire the
    sparkline, recent-lines, or unknown CTA from their own view; the
    default body renders nothing so the card stays purely shell-only
    in this UI-shell-only plan.

    Props:
      counterparty — anonymous object/array exposing { name, type, slug }
      recent       — array; up to two recent-activity rows (each: { date, label, amount })
--}}
@props([
    'counterparty',
    'recent' => [],
])
@php
    $cpType = is_array($counterparty) ? ($counterparty['type'] ?? CounterpartyType::Unknown->value) : ($counterparty->type ?? CounterpartyType::Unknown->value);
    $cpName = is_array($counterparty) ? ($counterparty['name'] ?? '—') : ($counterparty->name ?? '—');
    $cardClass = $cpType === CounterpartyType::Unknown->value ? 'cp-card unknown' : 'cp-card';
@endphp
<article
    {{ $attributes->merge(['class' => $cardClass]) }}
    aria-label="{{ Lang::get('counterparties::components.cp_card.aria', ['name' => $cpName]) }}"
>
    <header class="cp-head">
        <span class="cp-head-name">{{ $cpName }}</span>
        <x-counterparties::type-chip :type="$cpType" />
    </header>

    {{ $slot }}

    @if (! empty($recent))
        <div role="group" class="cp-recent" aria-label="{{ Lang::get('counterparties::components.cp_card.recent_aria') }}">
            @foreach (array_slice($recent, 0, 2) as $line)
                <span>{{ is_array($line) ? ($line['label'] ?? '') : $line }}</span>
            @endforeach
        </div>
    @endif
</article>
