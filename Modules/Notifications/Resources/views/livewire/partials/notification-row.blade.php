@use('Modules\Core\Public\Support\Lang')
{{--
    Notification row (18-UI-SPEC.md § 2). Markup is a verbatim
    clone of the UI-SPEC contract; the ONLY addition is the `x-on:click`
    below, which fires `markRead` in the background without intercepting
    the anchor's own navigation (Alpine's `$wire.call()` never calls
    `preventDefault()`) — the whole row stays the SINGLE click target
    and this just wires the "marking read flips state" behavior
    to the one target that already exists.

    Renders all five states correctly: unread (blue dot + font-semibold),
    read (no dot, font-normal), resolved (muted "Resolved" status-pill
    appended after the title), dead-link (aria-disabled, no hover
    background, cursor-default, quiet rose explanation line), and
    dismissed-tab (identical — no strikethrough, no extra muting).

    No day-grouping headers, no per-type chip color — `.type-chip`
    is the one new CSS class this row introduces.
--}}
<a
    href="{{ $notification->deepLinkUrl ?? '#' }}"
    @if (! $notification->readAt)
        x-on:click="$wire.markRead('{{ $notification->id }}')"
    @endif
    @if ($notification->deepLinkDisabled) aria-disabled="true" @endif
    class="block rounded-lg border border-slate-200 bg-white p-4 dark:bg-slate-950 dark:border-slate-700
           {{ $notification->readAt ? '' : 'relative' }}
           {{ $notification->deepLinkDisabled ? 'cursor-default' : 'hover:bg-slate-50 dark:hover:bg-slate-900' }}"
>
    <div class="flex items-start gap-3">
        @unless ($notification->readAt)
            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-blue-600 dark:bg-blue-400" aria-hidden="true"></span>
        @endunless
        <span class="type-chip" aria-hidden="true">{{ $notification->glyph }} {{ $notification->typeWord }}</span>
        <div class="min-w-0 flex-1">
            <p class="text-sm {{ $notification->readAt ? 'font-normal text-slate-700 dark:text-slate-300' : 'font-semibold text-slate-900 dark:text-slate-100' }}">
                {{ $notification->title }}
                @if ($notification->resolved())
                    <span class="status-pill muted ml-1">{{ Lang::get('notifications::row.resolved') }}</span>
                @endif
            </p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $notification->body }}</p>
            @if ($notification->deepLinkDisabled)
                <p class="mt-1 text-xs text-rose-700 dark:text-rose-400">{{ Lang::get('notifications::row.dead_link', ['kind' => $notification->targetKind]) }}</p>
            @endif
        </div>
        <span class="shrink-0 text-xs text-slate-400 dark:text-slate-500" style="font-variant-numeric: tabular-nums;">{{ $notification->relativeTime() }}</span>
    </div>
</a>
