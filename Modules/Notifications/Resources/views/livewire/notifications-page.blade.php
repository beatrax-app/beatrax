@use('Modules\Core\Public\Support\Lang')
{{--
    /notifications — the unified inbox.
    Direct clone of DriftPage's outer shape (Modules/DriftAlerts/Resources/
    views/livewire/drift-page.blade.php) minus its Level-1 type switch —
    this surface has only ONE level of tabs — /drift stays separate.

    Cursor pagination limit: 26 (25 + 1 lookahead), the exact
    NotificationQuery/DriftAlertQuery precedent.
--}}

@php
    use Modules\Notifications\Public\Services\NotificationQuery;

    $tabs = [
        'unread' => Lang::get('notifications::inbox.tabs.unread'),
        'all' => Lang::get('notifications::inbox.tabs.all'),
        'dismissed' => Lang::get('notifications::inbox.tabs.dismissed'),
    ];

    // D-44 — [heading, body] tuple per tab, verbatim from the Copywriting
    // Contract.
    $emptyStates = [
        'unread' => [
            Lang::get('notifications::inbox.empty.unread.heading'),
            Lang::get('notifications::inbox.empty.unread.body'),
        ],
        'all' => [
            Lang::get('notifications::inbox.empty.all.heading'),
            Lang::get('notifications::inbox.empty.all.body'),
        ],
        'dismissed' => [
            Lang::get('notifications::inbox.empty.dismissed.heading'),
            Lang::get('notifications::inbox.empty.dismissed.body'),
        ],
    ];
@endphp

<div class="mx-auto max-w-5xl px-4 py-12">
    {{-- Baseline, not items-start: the link is 14px beside a 24px title, so
         aligning their tops left it floating above the heading it belongs to. --}}
    <header class="mb-6 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ Lang::get('notifications::inbox.heading') }}</h1>
        </div>
        <a
            href="{{ route('settings') }}#notifications"
            class="text-sm text-slate-500 underline-offset-2 hover:text-slate-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-400 dark:hover:text-slate-100"
        >{{ Lang::get('notifications::inbox.settings_link') }}</a>
    </header>

    {{-- Single-level lifecycle tabs. The strip and its label are this page's;
         the buttons are the shared x-core::tab the copy here used to duplicate. --}}
    <nav
        class="mb-6 flex items-center gap-2 border-b border-slate-200 dark:border-slate-700"
        role="tablist"
        aria-label="{{ Lang::get('notifications::inbox.tablist_aria') }}"
        x-data="tabStrip()"
        x-on:keydown="onKey($event)"
    >
        @foreach ($tabs as $key => $label)
            <x-core::tab
                :active="$tab === $key"
                id="notifications-tab-{{ $key }}"
                aria-controls="notifications-tab-panel"
                tabindex="{{ $tab === $key ? '0' : '-1' }}"
                wire:click="setTab('{{ $key }}')"
            >{{ $label }}</x-core::tab>
        @endforeach
    </nav>

    {{-- One panel for every tab: only the selected tab's rows are rendered,
         so the panel takes its name from whichever tab is selected. --}}
    <div id="notifications-tab-panel" role="tabpanel" aria-labelledby="notifications-tab-{{ $tab }}">
    @if (count($rows) === 0)
        @php [$emptyHeading, $emptyBody] = $emptyStates[$tab] ?? $emptyStates['unread']; @endphp
        <div class="rounded-lg border border-slate-200 bg-white p-6 dark:bg-slate-950 dark:border-slate-700">
            <x-core::section-heading :title="$emptyHeading" />
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
                >{{ Lang::get('notifications::inbox.load_more') }}</button>
            </div>
        @endif
    @endif
    </div>
</div>
