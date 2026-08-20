@props([
    'lead' => false,    // The first step, inverted so the eye starts there.
])

{{--
    The numbered disc beside a step in the OAuth client wizard.

    Twelve of them — six Google steps, six Microsoft — each carrying the same
    120-character class string inline, which is twelve places for the disc to
    drift apart. The number is the slot.
--}}
<span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold {{ $lead
    ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900'
    : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400' }}">{{ $slot }}</span>
