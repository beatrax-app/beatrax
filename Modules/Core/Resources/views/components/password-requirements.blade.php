@use('Modules\Auth\Public\Contracts\PasswordPolicy')
@props([
    'ariaLabel',                    // Required. The list's accessible name, in the reader's language.
    'lengthLabel',                  // Required. The row that reads "at least N characters".
    'matchLabel',                   // Required. The row that reads "the two boxes agree".
    'met',                          // Required. The screen-reader-only suffix on a ticked row.
    'unmet',                        // Required. The same suffix for a row not yet ticked.
    'id' => 'password-requirements',    // The aria-describedby target the password field names.
    'minLength' => PasswordPolicy::MINIMUM_LENGTH,      // What the length row measures against.
    'passwordProperty' => 'password',                   // The $wire property holding the passphrase.
    'confirmationProperty' => 'passwordConfirmation',   // The $wire property holding the repeat of it.
])

{{--
    The live requirement checklist under a new-password pair.

    Two of these existed — the desktop signup screen and the mobile import
    screen — born the same day, byte-identical across 29 lines, differing
    only by which lang namespace the five strings came from. That is what
    the five string props are for: the namespace is an attribute here, not
    a fork.

    ARIA is the reason this is a component rather than a snippet. The list
    is the password field's `aria-describedby` target, so its id and that
    reference are one contract; `aria-live="polite"` is what announces a
    row ticking without stealing focus from the box being typed into; and
    the tick glyph is `aria-hidden` because a decorative SVG announces
    nothing useful, which is why each row carries a `met`/`unmet` suffix in
    words instead.

    The reading of the two boxes lives in `Alpine.data('passwordStrength')`
    in resources/js/app.js rather than in an `x-data` object here. An
    expression written into an attribute is one stray double quote away
    from ending the attribute and spilling the rest of the screen into the
    page as text, which has shipped once already on the recovery screen.
--}}

<ul
    id="{{ $id }}"
    aria-live="polite"
    aria-label="{{ $ariaLabel }}"
    x-data="passwordStrength({{ (int) $minLength }}, @js($passwordProperty), @js($confirmationProperty))"
    {{ $attributes->merge(['class' => 'space-y-1.5']) }}
>
    {{-- met/unmet travel on the row object rather than being written into
         each clone's own x-text: Alpine compiles a clone's expression once, so
         a re-render in another language left the suffix in the old one while
         the label beside it changed. --}}
    <template x-for="req in [
        { label: @js($lengthLabel), ok: lengthOk, met: @js($met), unmet: @js($unmet) },
        { label: @js($matchLabel), ok: matchOk, met: @js($met), unmet: @js($unmet) },
    ]" :key="req.label">
        <li class="flex items-center gap-2 text-xs transition-colors"
            :class="req.ok ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400 dark:text-slate-500'">
            <span
                class="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full border transition-colors"
                :class="req.ok
                    ? 'border-emerald-600 bg-emerald-600 text-white dark:border-emerald-400 dark:bg-emerald-400 dark:text-slate-950'
                    : 'border-slate-300 text-transparent dark:border-slate-600'"
                aria-hidden="true"
            >
                <svg viewBox="0 0 12 12" class="h-2.5 w-2.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M2.5 6.5 4.8 8.8 9.5 3.5" />
                </svg>
            </span>
            <span x-text="req.label"></span>
            <span class="sr-only" x-text="req.ok ? req.met : req.unmet"></span>
        </li>
    </template>
</ul>
