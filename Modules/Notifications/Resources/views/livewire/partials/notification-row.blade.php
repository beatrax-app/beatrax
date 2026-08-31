@use('Modules\Core\Public\Support\Lang')
{{--
    Notification row (18-UI-SPEC.md § 2). Markup follows the UI-SPEC
    contract with three additions. flex-wrap inside the anchor: at the reader's
    accessibility text sizes the chip, the title and the relative time each
    want the whole width, and a row that could not wrap put "4 hours ago"
    280px past the screen. And the `x-on:click`
    below, which fires `markRead` in the background without intercepting
    the anchor's own navigation (Alpine's `$wire.call()` never calls
    `preventDefault()`) — the anchor stays the SINGLE navigation target.

    Third: the action marks are SIBLINGS of the anchor, never inside it, which
    is how the transactions list carries a split badge on a row that navigates.
    Nesting a button in an anchor is invalid, and a tap on it would follow the
    link as well as fire the action. flex-wrap on the outer wrapper is what
    keeps that safe on a phone: app.css gives `.flex-1` a content basis at a
    coarse pointer, so the anchor asks for the whole line and the marks take a
    second one under it rather than squeezing the title. Measured at 375px and
    411px against the built stylesheet: nothing overflows, every mark clears
    44px, and no caption breaks a line.

    Dismiss carries no confirmation on purpose: it is reversible, the toast
    offers the Undo, and the convention prefers a reversible action over a
    question. Mark-read is offered explicitly as well as on open, because the
    x-on:click above rides a full page navigation that tears the document down
    under the request, and because clearing an inbox otherwise means opening
    every target and coming back.

    Renders all five states correctly: unread (blue dot + font-semibold),
    read (no dot, font-normal), resolved (muted "Resolved" status-pill
    appended after the title), dead-link (aria-disabled, no hover
    background, cursor-default, quiet rose explanation line), and
    dismissed-tab (identical — no strikethrough, no extra muting).

    No day-grouping headers, no per-type chip color — `.type-chip`
    is the one new CSS class this row introduces.
--}}
<div class="flex flex-wrap items-center rounded-lg border border-slate-200 bg-white dark:bg-slate-950 dark:border-slate-700">
    <a
        href="{{ $notification->deepLinkUrl ?? '#' }}"
        @if (! $notification->readAt)
            x-on:click="$wire.markRead('{{ $notification->id }}')"
        @endif
        @if ($notification->deepLinkDisabled) aria-disabled="true" @endif
        class="block min-w-0 flex-1 rounded-lg p-4
               {{ $notification->readAt ? '' : 'relative' }}
               {{ $notification->deepLinkDisabled ? 'cursor-default' : 'hover:bg-slate-50 dark:hover:bg-slate-900' }}"
    >
        <div class="flex flex-wrap items-start gap-3">
            @unless ($notification->readAt)
                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-blue-600 dark:bg-blue-400" aria-hidden="true"></span>
            @endunless
            <span class="type-chip" aria-hidden="true">{{ $notification->glyph }} {{ $notification->typeWord }}</span>
            <div class="min-w-0 flex-1">
                <p class="text-sm {{ $notification->readAt ? 'font-normal text-slate-700 dark:text-slate-300' : 'font-semibold text-slate-900 dark:text-slate-100' }}">
                    {{ $notification->unreadable ? Lang::get('notifications::row.unreadable') : $notification->title }}
                    @if ($notification->resolved())
                        <span class="status-pill muted ml-1">{{ Lang::get('notifications::row.resolved') }}</span>
                    @endif
                </p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $notification->body }}</p>
                {{-- An unreadable row has no readable params either, so the deep
                     link resolves to nothing and the dead-link line would tell the
                     reader the item is gone when it is only sealed. A null
                     targetKind is the same claim about a row that never named a
                     target at all. --}}
                @if ($notification->deepLinkDisabled && ! $notification->unreadable && $notification->targetKind !== null)
                    <p class="mt-1 text-xs text-rose-700 dark:text-rose-400">{{ Lang::get('notifications::row.dead_link.'.$notification->targetKind) }}</p>
                @endif
            </div>
            <span class="shrink-0 text-xs text-slate-600 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">{{ $notification->relativeTime() }}</span>
        </div>
    </a>
    {{-- flex-wrap here as well as on the row: two captioned marks at the
         reader's largest text size are wider than the strip they sit in. --}}
    <div class="ml-auto flex shrink-0 flex-wrap items-center justify-end gap-1 pb-4 pr-4">
        @if ($notification->dismissedAt)
            <x-core::emoji-action
                :label="Lang::get('notifications::row.actions.restore')"
                wire:click="undoDismiss('{{ $notification->id }}')"
            >↩️</x-core::emoji-action>
        @else
            @unless ($notification->readAt)
                <x-core::emoji-action
                    :label="Lang::get('notifications::row.actions.mark_read')"
                    :caption="Lang::get('notifications::row.actions.mark_read_caption')"
                    wire:click="markRead('{{ $notification->id }}')"
                >✅</x-core::emoji-action>
            @endunless
            <x-core::emoji-action
                :label="Lang::get('notifications::row.actions.dismiss')"
                wire:click="dismiss('{{ $notification->id }}')"
            >✖️</x-core::emoji-action>
        @endif
    </div>
</div>
