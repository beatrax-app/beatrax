{{--
    The full-width action that closes a form: sign in, save, parse, import.

    Seven forms across three modules each spelled out the same 200-character
    emerald class string, and the copies had already begun to disagree about
    the order of their dark: variants. The label is the slot.

    `type` is a merge default rather than a literal, so a caller that acts on
    click instead of submitting can pass type="button" without emitting the
    attribute twice.
--}}
<button {{ $attributes->merge([
    'type' => 'submit',
    'class' => 'w-full bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-md py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:hover:bg-emerald-400 dark:bg-emerald-500',
]) }}>
    {{ $slot }}
</button>
