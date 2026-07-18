{{--
    /notifications — the unified inbox (Req 2, Req 3; D-01, D-02, D-04).
    Direct clone of DriftPage's outer shape (Modules/DriftAlerts/Resources/
    views/livewire/drift-page.blade.php) minus its Level-1 type switch —
    this surface has only ONE level of tabs (D-02: /drift stays separate).

    Cursor pagination limit: 26 (25 + 1 lookahead), the exact
    NotificationQuery/DriftAlertQuery precedent.
--}}

@php
    use Modules\Notifications\Public\Services\NotificationQuery;

    $tabs = [
        'unread' => 'Unread',
        'all' => 'All',
        'dismissed' => 'Dismissed',
    ];

    // D-44 — [heading, body] tuple per tab, verbatim from the Copywriting
    // Contract.
    $emptyStates = [
        'unread' => [
            "You're all caught up",
            'New notifications land here — payment reminders, budget nudges, and your weekly position.',
        ],
        'all' => [
            'Nothing yet',
            'beatrax will let you know about upcoming payments and anything that needs a look.',
        ],
        'dismissed' => [
            'Nothing dismissed',
            'Notifications you dismiss are kept here for a while.',
        ],
    ];
@endphp

<div class="mx-auto max-w-5xl px-4 py-12">
    <header class="mb-6 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Notifications</h1>
        </div>
        <a
            href="{{ route('settings') }}#notifications"
            class="text-sm text-slate-500 underline-offset-2 hover:text-slate-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-400 dark:hover:text-slate-100"
        >Notification settings →</a>
    </header>

    {{-- Single-level lifecycle tabs, cloned verbatim from DriftPage's Level-2 tabs (D-04). --}}
    <nav class="mb-6 flex items-center gap-2 border-b border-slate-200 dark:border-slate-700" role="tablist" aria-label="Notification lifecycle">
        @foreach ($tabs as $key => $label)
            <button
                type="button"
                role="tab"
                aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                wire:click="setTab('{{ $key }}')"
                @class([
                    'px-3 py-2 text-sm',
                    'border-b-2 border-slate-900 font-medium text-slate-900 dark:border-slate-100 dark:text-slate-100' => $tab === $key,
                    'border-b-2 border-transparent text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100' => $tab !== $key,
                ])
            >{{ $label }}</button>
        @endforeach
    </nav>

    @if (count($rows) === 0)
        @php [$emptyHeading, $emptyBody] = $emptyStates[$tab] ?? $emptyStates['unread']; @endphp
        <div class="rounded-lg border border-slate-200 bg-white p-6 dark:bg-slate-950 dark:border-slate-700">
            <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ $emptyHeading }}</h2>
            <p class="mt-2 max-w-prose text-sm text-slate-500 dark:text-slate-400">{{ $emptyBody }}</p>
        </div>
    @else
        <ul class="space-y-3" aria-live="polite">
            @foreach ($rows as $notification)
                <li>
                    @include('notifications::livewire.partials.notification-row', ['notification' => $notification])
                </li>
            @endforeach
        </ul>
        @if (count($rows) >= 26)
            @php
                $lastRow = $rows[count($rows) - 1];
                $nextCursor = NotificationQuery::encodeCursor($lastRow->createdAt->toDateTimeString(), $lastRow->id);
            @endphp
            <div class="mt-6 flex justify-center">
                <button
                    type="button"
                    wire:click="$set('cursor', '{{ $nextCursor }}')"
                    class="text-sm text-slate-500 underline-offset-2 hover:text-slate-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-400 dark:hover:text-slate-100"
                >Load more</button>
            </div>
        @endif
    @endif
</div>
