{{--
    Renders one system_alerts row's body: the headline message, the
    timestamp + row id line, and the per-row action buttons.

    Variables in scope:
      - $alert : Modules\Core\Models\SystemAlert

    The body is severity-independent. Everything that differs between a
    critical, a warning and an informational row is carried by the
    wrapping x-core::alert — its tone class string and its live-region
    semantics — so this markup is included once per row whatever the
    severity is.

    Each row stacks below `sm` and only sits the actions beside the
    message from `sm` up. The buttons do not shrink, so on a phone a
    side-by-side row left the message a ~130px column and broke one
    sentence over six lines.
--}}
<div class="flex flex-col items-start gap-3 sm:flex-row sm:justify-between sm:gap-4">
    <div class="min-w-0 flex-1">
        <p class="text-sm font-medium">@include('core::livewire.partials.system-alert-message', ['alert' => $alert])</p>
        <p class="mt-1 text-xs" style="font-variant-numeric: tabular-nums;">
            {{ $alert->created_at->translatedFormat('d M Y · H:i') }}
            <span class="mx-1">·</span>
            #{{ $alert->id }}
        </p>
    </div>
    <div class="shrink-0">
        @include('core::livewire.partials.system-alert-actions', ['alert' => $alert])
    </div>
</div>
