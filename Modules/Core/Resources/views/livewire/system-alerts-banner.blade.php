{{--
    Persistent banner surfacing un-acknowledged system_alerts rows.

    Variables in scope:
      - $alerts : Illuminate\Database\Eloquent\Collection<int, Modules\Core\Models\SystemAlert>

    The banner is INVISIBLE when calm (no active rows) — the outer
    wrapper renders empty so screen-readers can navigate to the
    landmark, but nothing is painted on screen.

    Severity branches are three explicit Blade snippets containing
    literal Tailwind class strings. Tailwind's content scanner needs
    direct string occurrences in source — no `border-{tier}-500`
    interpolation, no PurgeCSS safelist comments.

    Every interpolation uses Blade {{ }} default escaping. Unescaped
    output (raw-output Blade) is forbidden in this view —
    system_alerts.message may carry operator-controlled text that we
    treat as untrusted.
--}}
<div role="region" aria-label="System alerts" class="px-6 space-y-2">
    @foreach ($alerts as $alert)
        @switch ($alert->severity)
            @case ('critical')
                <div role="alert" class="rounded-lg border border-rose-500 bg-rose-50 text-rose-900 p-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium">@include('core::livewire.partials.system-alert-message', ['alert' => $alert])</p>
                            <p class="mt-1 text-xs" style="font-variant-numeric: tabular-nums;">
                                {{ $alert->created_at->format('d M Y · H:i') }}
                                <span class="mx-1">·</span>
                                #{{ $alert->id }}
                            </p>
                        </div>
                        <div class="shrink-0">
                            <button
                                type="button"
                                wire:click="acknowledge({{ $alert->id }})"
                                wire:loading.attr="disabled"
                                wire:target="acknowledge({{ $alert->id }})"
                                aria-label="Mark system alert #{{ $alert->id }} as resolved"
                                class="rounded bg-rose-600 text-white hover:bg-rose-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-rose-600 px-3 py-1.5 text-sm font-medium"
                            >Mark as resolved</button>
                        </div>
                    </div>
                </div>
                @break
            @case ('warning')
                <div role="status" class="rounded-lg border border-amber-300 bg-amber-50 text-amber-900 p-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium">@include('core::livewire.partials.system-alert-message', ['alert' => $alert])</p>
                            <p class="mt-1 text-xs" style="font-variant-numeric: tabular-nums;">
                                {{ $alert->created_at->format('d M Y · H:i') }}
                                <span class="mx-1">·</span>
                                #{{ $alert->id }}
                            </p>
                        </div>
                        <div class="shrink-0">
                            <button
                                type="button"
                                wire:click="acknowledge({{ $alert->id }})"
                                wire:loading.attr="disabled"
                                wire:target="acknowledge({{ $alert->id }})"
                                aria-label="Mark system alert #{{ $alert->id }} as resolved"
                                class="rounded bg-slate-100 text-slate-700 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900 px-3 py-1.5 text-sm font-medium"
                            >Mark as resolved</button>
                        </div>
                    </div>
                </div>
                @break
            @default
                <div role="status" class="rounded-lg border border-slate-200 bg-slate-50 text-slate-700 p-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium">@include('core::livewire.partials.system-alert-message', ['alert' => $alert])</p>
                            <p class="mt-1 text-xs" style="font-variant-numeric: tabular-nums;">
                                {{ $alert->created_at->format('d M Y · H:i') }}
                                <span class="mx-1">·</span>
                                #{{ $alert->id }}
                            </p>
                        </div>
                        <div class="shrink-0">
                            <button
                                type="button"
                                wire:click="acknowledge({{ $alert->id }})"
                                wire:loading.attr="disabled"
                                wire:target="acknowledge({{ $alert->id }})"
                                aria-label="Mark system alert #{{ $alert->id }} as resolved"
                                class="rounded bg-slate-100 text-slate-700 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-900 px-3 py-1.5 text-sm font-medium"
                            >Mark as resolved</button>
                        </div>
                    </div>
                </div>
        @endswitch
    @endforeach
</div>
