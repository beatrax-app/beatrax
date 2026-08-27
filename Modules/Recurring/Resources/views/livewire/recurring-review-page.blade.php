@use('Modules\Core\Public\Enums\SnoozeWindow')
@use('Modules\Recurring\Internal\Enums\ReviewTab')
@use('Modules\Core\Public\Support\Lang')
{{--
    /recurring/review page — Pending / Rejected / Cadence-changed
    tabs over recurring_series rows. Each pending or cadence-changed
    row carries Approve / Reject / Snooze / Edit-name actions;
    rejected rows carry an Un-reject action. Approve / Reject /
    Snooze / Edit-name each dispatch a 10-second Undo toast via
    `$this->dispatch('toast', ...)` (the layout binds `x-on:toast` on
    the window).

    Blade default `{{ }}` escaping for every interpolation. No raw
    HTML output anywhere.
--}}

@php
    use Modules\Ledger\Public\ValueObjects\Money;

    $fmt = static fn (Money $money): string => $money->format();

    // The neutral chip in the row's action cluster: Un-reject, and the two
    // popover triggers. It carries the same box as the emerald Approve and
    // rose Reject beside it, so the five wrap onto a phone line as one set;
    // only the fill and the text colour tell them apart.
    $rowActionClass = 'inline-flex items-center gap-1 rounded-md bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700';

    // Both popovers are anchored to the row rather than to their button below
    // sm: right-aligned to a trigger near the left edge, a 192px panel opens
    // off the left of a 375px screen. The `sm:relative` on each wrapper is the
    // other half — it is what `sm:left-auto sm:right-0` re-anchors against.
    $rowPopoverClass = 'absolute inset-x-0 z-10 mt-1 rounded-md border border-slate-200 bg-white p-2 shadow-lg sm:left-auto sm:right-0 dark:bg-slate-950 dark:border-slate-700';
@endphp

<div class="mx-auto max-w-5xl px-4 py-12">
    <header class="mb-8">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ Lang::get('recurring::review.title') }}</h1>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
            {{ Lang::get('recurring::review.subtitle') }}
        </p>
    </header>

    <nav class="mb-6 flex items-center gap-2 border-b border-slate-200 dark:border-slate-700">
        @foreach (ReviewTab::cases() as $tabOption)
            <button
                type="button"
                wire:click="setTab('{{ $tabOption->value }}')"
                @class([
                    'px-3 py-2 text-sm',
                    'border-b-2 border-slate-900 font-medium text-slate-900 dark:border-slate-100 dark:text-slate-100' => $tabOption === $reviewTab,
                    'border-b-2 border-transparent text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100' => $tabOption !== $reviewTab,
                ])
            >{{ Lang::get($tabOption->labelKey()) }}</button>
        @endforeach
    </nav>

    @if (count($selectedIds) > 0)
        <section
            class="fixed bottom-4 left-1/2 z-40 flex -translate-x-1/2 items-center gap-3 rounded-full border border-slate-200 bg-white px-4 py-2 shadow-lg dark:bg-slate-950 dark:border-slate-700"
            aria-label="{{ Lang::get('recurring::review.bulk.aria') }}"
        >
            <span class="text-xs text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">{{ Lang::get('recurring::review.bulk.selected', ['count' => count($selectedIds)]) }}</span>
            <button
                type="button"
                wire:click="bulkApprove"
                class="inline-flex items-center gap-1 rounded-md bg-emerald-700 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700 focus-visible:ring-offset-2 dark:bg-emerald-700 dark:hover:bg-emerald-800"
            >{{ Lang::get('recurring::review.bulk.approve', ['count' => count($selectedIds)]) }}</button>
            <button
                type="button"
                wire:click="bulkReject"
                class="inline-flex items-center gap-1 rounded-md bg-rose-50 px-3 py-1.5 text-xs font-medium text-rose-600 hover:bg-rose-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2 dark:bg-rose-950 dark:text-rose-500 dark:hover:bg-rose-900"
            >{{ Lang::get('recurring::review.bulk.reject', ['count' => count($selectedIds)]) }}</button>
        </section>
    @endif

    @if (count($rows) === 0)
        @php
            $emptyBody = Lang::get($reviewTab->emptyBodyKey());
        @endphp
        <x-core::empty-state
            :heading="Lang::get('recurring::review.empty.heading')"
            :body="$emptyBody"
        />
    @else
        {{-- The row reflows rather than scrolls. Held at 560px it was 202px
             wider than a 375px phone, and the horizontal scroller meant to
             rescue it was never reached by a swipe: Snooze showed 23px of its
             80 and Edit name sat entirely off-screen, with no way to get to
             either. The actions take their own line below the name instead. --}}
        <ul class="space-y-3">
            @foreach ($rows as $row)
                <li class="relative rounded-lg border border-slate-200 bg-slate-50 p-4 dark:bg-slate-900 dark:border-slate-700">
                    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
                        <div class="min-w-0 flex-1 basis-full sm:basis-auto">
                            {{-- The four buttons below carry the series id in their
                                 aria-label because none of them has a visible name of
                                 its own. This one does, so it takes it: the merchant
                                 disambiguates the card better than its row id, and it
                                 is the same words the eye reads. --}}
                            <p class="flex flex-wrap items-center gap-x-2 text-sm text-slate-900 dark:text-slate-100">
                                <x-core::checkbox-field
                                    :label="$row->displayName()"
                                    :field-id="'recurring-select-'.$row->seriesId"
                                    wire:model.live="selectedIds"
                                    value="{{ $row->seriesId }}"
                                    class="font-medium"
                                />
                                <span class="text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">{{ $fmt($row->latestAmount) }}</span>
                            </p>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                {{ $row->cadence->label() }}
                                @if ($row->nextExpectedAt)
                                    · {{ Lang::get($row->expectedChargeIsLate($today) ? 'recurring::review.overdue' : 'recurring::review.next') }} {{ $row->nextExpectedAt->translatedFormat('d M Y') }}
                                @endif
                                @if ($row->state === \Modules\Recurring\Public\Enums\RecurringSeriesState::CadenceChanged->value)
                                    · {{ Lang::get('recurring::review.cadence_changed_note') }}
                                @endif
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($reviewTab === ReviewTab::Rejected)
                                <button
                                    type="button"
                                    wire:click="unReject({{ $row->seriesId }})"
                                    class="{{ $rowActionClass }}"
                                >{{ Lang::get('recurring::review.un_reject') }}</button>
                            @else
                                <button
                                    type="button"
                                    wire:click="approve({{ $row->seriesId }})"
                                    aria-label="{{ Lang::get('recurring::review.approve_aria', ['id' => $row->seriesId]) }}"
                                    class="inline-flex items-center gap-1 rounded-md bg-emerald-700 px-2.5 py-1 text-xs font-medium text-white hover:bg-emerald-800 dark:bg-emerald-700 dark:hover:bg-emerald-800"
                                >{{ Lang::get('recurring::review.approve') }}</button>
                                <button
                                    type="button"
                                    wire:click="reject({{ $row->seriesId }})"
                                    aria-label="{{ Lang::get('recurring::review.reject_aria', ['id' => $row->seriesId]) }}"
                                    class="inline-flex items-center gap-1 rounded-md bg-rose-50 px-2.5 py-1 text-xs font-medium text-rose-600 hover:bg-rose-100 dark:bg-rose-950 dark:text-rose-500 dark:hover:bg-rose-900"
                                >{{ Lang::get('recurring::review.reject') }}</button>
                                <div x-data="{ open: false }" class="sm:relative">
                                    <button
                                        type="button"
                                        x-on:click="open = ! open"
                                        aria-label="{{ Lang::get('recurring::review.snooze_aria', ['id' => $row->seriesId]) }}"
                                        class="{{ $rowActionClass }}"
                                    >{{ Lang::get('recurring::review.snooze') }}</button>
                                    {{-- text-xs sizes the window buttons below, which set no size of
                                         their own — it is this panel's, not the shared shape's. Drop
                                         it and the menu falls back to the 16px document default. --}}
                                    <div
                                        x-show="open"
                                        x-cloak
                                        x-on:click.outside="open = false"
                                        class="{{ $rowPopoverClass }} text-xs sm:w-48"
                                    >
                                        @foreach (SnoozeWindow::cases() as $window)
                                            <button
                                                type="button"
                                                wire:click="snooze({{ $row->seriesId }}, '{{ $snoozeTargets[$window->value] }}')"
                                                x-on:click="open = false"
                                                class="block w-full px-2 py-1 text-left hover:bg-slate-50 dark:hover:bg-slate-900"
                                            >{{ Lang::get($window->labelKey('recurring::review')) }}</button>
                                        @endforeach
                                    </div>
                                </div>
                                <div x-data="{ editing: false, newName: @js($row->displayName()) }" class="sm:relative">
                                    <button
                                        type="button"
                                        x-on:click="editing = ! editing"
                                        aria-label="{{ Lang::get('recurring::review.edit_name_aria', ['id' => $row->seriesId]) }}"
                                        class="{{ $rowActionClass }}"
                                    >{{ Lang::get('recurring::review.edit_name') }}</button>
                                    <div
                                        x-show="editing"
                                        x-cloak
                                        x-on:click.outside="editing = false"
                                        class="{{ $rowPopoverClass }} sm:w-64"
                                    >
                                        <label for="series-name-{{ $row->seriesId }}" class="sr-only">{{ Lang::get('recurring::review.new_name_label') }}</label>
                                        <input
                                            id="series-name-{{ $row->seriesId }}"
                                            type="text"
                                            x-model="newName"
                                            class="block w-full rounded-md border border-slate-200 px-2 py-1 text-xs dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100"
                                        />
                                        <x-core::neutral-button
                                            size="sm"
                                            class="mt-2 gap-1"
                                            x-on:click="$wire.editName({{ $row->seriesId }}, newName); editing = false"
                                        >{{ Lang::get('recurring::review.save') }}</x-core::neutral-button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
