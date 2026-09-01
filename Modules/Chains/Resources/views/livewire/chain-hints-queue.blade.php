@use('Modules\Core\Public\Support\Lang')
{{-- /chains/hints — dedicated review surface for hint-shaped chain_links.
     Mirrors the calm aesthetic of /chains/review (Linear/Notion-ish);
     adds a per-kind label and an evidence summary list since hints
     never carry a "to" side. --}}
@php
    $kindLabel = static function (string $kind): string {
        return match ($kind) {
            'ics_bulk_settle' => Lang::get('chains::hints.kind.ics_bulk_settle'),
            'funded_by_card_hint' => Lang::get('chains::hints.kind.funded_by_card_hint'),
            'refund_of_hint' => Lang::get('chains::hints.kind.refund_of_hint'),
            default => $kind,
        };
    };
    $fmt = static fn ($money) => $money->format();
@endphp

<div class="mx-auto max-w-5xl px-4 py-6">
    <header class="mb-8">
        <div class="flex flex-wrap items-baseline justify-between gap-4">
            <x-core::page-heading>{{ Lang::get('chains::hints.heading') }}</x-core::page-heading>
            <a
                href="{{ route('chains.review') }}"
                class="tap-link text-xs text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200"
            >{{ Lang::get('chains::hints.back_to_review') }}</a>
        </div>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
            {{ Lang::get('chains::hints.subtitle') }}
        </p>
    </header>

    @if ($statusMessage)
        <x-core::alert
            tone="positive"
            class="mb-6"
            aria-live="polite" aria-atomic="true"
            data-testid="chain-hints-status"
        >
            {{ $statusMessage }}
        </x-core::alert>
    @endif

    @if (count($hints) === 0)
        <x-core::empty-state
            data-testid="chain-hints-empty"
            :heading="Lang::get('chains::hints.empty_heading')"
            :body="Lang::get('chains::hints.empty_body')"
        />
    @else
        {{-- overflow-x: auto wrapper so dense hint rows scroll horizontally at phone width --}}
        <div class="overflow-x-scroll-wrapper" style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
        <ul class="space-y-4" data-testid="chain-hints-list" style="min-width: 480px;">
            @foreach ($hints as $hint)
                <li
                    class="rounded-lg border border-slate-200 bg-slate-50 p-4 dark:bg-slate-900 dark:border-slate-700"
                    data-testid="chain-hint-row-{{ $hint->chainLinkId }}"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-medium uppercase tracking-wide text-amber-700 dark:text-amber-400">
                                {{ $kindLabel($hint->kind) }}
                            </p>
                            {{-- Counterparty name links to its profile
                                 when the from-row has been resolved by
                                 the CounterpartyResolver chain; plain
                                 text otherwise so the row still renders
                                 cleanly for unresolved sources. --}}
                            <p class="mt-1 text-sm text-slate-900 dark:text-slate-100">
                                @if ($hint->fromCounterpartySlug !== null)
                                    <a
                                        href="{{ route('counterparties.profile', ['slug' => $hint->fromCounterpartySlug]) }}"
                                        wire:navigate
                                        class="tap-link underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:focus-visible:ring-slate-100"
                                        data-testid="chain-hint-from-counterparty-link-{{ $hint->chainLinkId }}"
                                    >{{ $hint->fromCounterparty ?: Lang::get('chains::hints.no_counterparty') }}</a>
                                @else
                                    <span data-testid="chain-hint-from-counterparty-text-{{ $hint->chainLinkId }}">{{ $hint->fromCounterparty ?: Lang::get('chains::hints.no_counterparty') }}</span>
                                @endif
                                <span
                                    class="ml-2 text-slate-500 dark:text-slate-400"
                                    style="font-variant-numeric: tabular-nums;"
                                >{{ $fmt($hint->fromAmount) }}</span>
                            </p>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                {{ $hint->fromAccountName ?: Lang::get('chains::hints.unknown_account') }} ·
                                {{ $hint->fromPostedAt->translatedFormat('d M Y') }}
                            </p>
                            @if (count($hint->evidenceLines) > 0)
                                <ul class="mt-3 space-y-0.5 text-xs text-slate-600 dark:text-slate-400">
                                    @foreach ($hint->evidenceLines as $line)
                                        <li class="flex items-start gap-1.5">
                                            <span aria-hidden="true" class="mt-1 inline-block h-1 w-1 rounded-full bg-slate-400 dark:bg-slate-500"></span>
                                            <span>{{ $line }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                        <div class="flex shrink-0 items-center">
                            <x-core::secondary-button
                                size="sm"
                                class="gap-1"
                                wire:click="dismiss('{{ $hint->chainLinkId }}')"
                                aria-label="{{ Lang::get('chains::hints.dismiss_aria', ['id' => $hint->chainLinkId]) }}"
                                data-testid="chain-hint-dismiss-{{ $hint->chainLinkId }}"
                            >{{ Lang::get('chains::hints.dismiss') }}</x-core::secondary-button>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
        </div>{{-- /overflow-x-scroll-wrapper --}}
    @endif
</div>
