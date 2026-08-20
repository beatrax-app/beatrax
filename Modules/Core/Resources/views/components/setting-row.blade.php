@props([
    'label',                 // Required. The setting's name, in body weight — not a heading.
    'description' => null,   // The line under it. Pass <x-slot:description> when it needs markup.
])

{{--
    One settings line: what the setting is on the left, the control that
    changes it on the right.

    Six of these existed across app-lock, sync and the two mobile settings
    screens, each re-typing the same flex/typography stack around a switch.
    The control is the slot, so this holds no state of its own — the toggle,
    the button or the status pill still lives at the call site with its
    wire:click and its aria-pressed.
--}}
<div {{ $attributes->merge(['class' => 'flex items-start justify-between gap-4']) }}>
    <div class="flex-1 min-w-0">
        <p class="text-sm text-slate-900 dark:text-slate-100">{{ $label }}</p>
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $description }}</p>
    </div>
    {{ $slot }}
</div>
