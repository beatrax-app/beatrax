@use('Modules\Core\Public\Navigation\Destination')
@use('Modules\Core\Public\Support\Lang')
@use('Modules\Notifications\Internal\Enums\NotificationTab')
{{--
    /notifications — the unified inbox.
    Direct clone of DriftPage's outer shape (Modules/DriftAlerts/Resources/
    views/livewire/drift-page.blade.php) minus its Level-1 type switch —
    this surface has only ONE level of tabs — /drift stays separate.

    Cursor pagination: NotificationQuery decides the page size and hands
    back the cursor that follows it, so no page count is written here.
--}}

@php
    // Every tab this page has, in the enum's own order: a strip written out
    // here is a second list of them, and the one that goes stale.
    $tabs = NotificationTab::cases();
@endphp

<div class="mx-auto max-w-5xl px-4 py-6">
    {{-- Baseline, not items-start: the link is 14px beside a 24px title, so
         aligning their tops left it floating above the heading it belongs to. --}}
    <header class="mb-6 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <div>
            <x-core::page-heading>{{ Lang::get('notifications::inbox.heading') }}</x-core::page-heading>
        </div>
        <a
            href="{{ Destination::Settings->url() }}#notifications"
            class="tap-link text-sm text-slate-500 underline-offset-2 hover:text-slate-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-400 dark:hover:text-slate-100"
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
        @foreach ($tabs as $case)
            <x-core::tab
                :active="$activeTab === $case"
                id="notifications-tab-{{ $case->value }}"
                aria-controls="notifications-tab-panel"
                tabindex="{{ $activeTab === $case ? '0' : '-1' }}"
                wire:click="setTab('{{ $case->value }}')"
            >{{ Lang::get($case->labelKey()) }}</x-core::tab>
        @endforeach
    </nav>

    {{-- One panel for every tab: only the selected tab's rows are rendered,
         so the panel takes its name from whichever tab is selected. --}}
    <div id="notifications-tab-panel" role="tabpanel" aria-labelledby="notifications-tab-{{ $activeTab->value }}">
    @if (count($rows) === 0)
        <div class="rounded-lg border border-slate-200 bg-white p-6 dark:bg-slate-950 dark:border-slate-700">
            <x-core::section-heading :title="Lang::get($activeTab->emptyHeadingKey())" />
            <p class="mt-2 max-w-prose text-sm text-slate-500 dark:text-slate-400">{{ Lang::get($activeTab->emptyBodyKey()) }}</p>
        </div>
    @else
        <ul class="space-y-3" aria-live="polite">
            {{-- wire:key, now that a row can leave the list under the reader:
                 without it Livewire's morph pairs the survivors against the
                 wrong snapshot children and a row inherits its neighbour's
                 text and action ids. --}}
            @foreach ($rows as $notification)
                <li wire:key="notification-{{ $notification->id }}">
                    @include('notifications::livewire.partials.notification-row', ['notification' => $notification])
                </li>
            @endforeach
        </ul>
        @if ($nextCursor !== null)
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
