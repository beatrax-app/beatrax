@use('Modules\Core\Public\Support\Lang')
{{--
    PIN pad partial — calm-slate token classes, accessible targets.

    Requires the surrounding Alpine scope from lock-screen.blade.php:
    `pin` (local string), press(d), back(), submitPin().

    Threat mitigation: digits accumulate in client-side Alpine state — the
    dots below render from the local `pin`, the buttons mutate it locally,
    and only submitPin() sends the PIN to the server (once, as a method
    argument). Digits are displayed as bullet glyphs in an aria-live region,
    never as an `input` of type `password`. No autocomplete attribute is set.
    OS password managers and screenshot tools see only bullets.

    Accessibility contract (UI-SPEC):
      - aria-label="Digit N" on each digit button.
      - aria-label="Backspace" on the backspace button.
      - aria-label="OK — confirm PIN" on the submit button.
      - dot display wrapped in aria-live="polite" region announcing "{N} digits entered".
        The eleven possible announcements are chosen server-side, because a
        locale with more than two plural forms cannot be served by a suffix
        glued onto a number in the browser.
      - backoff label in aria-live="assertive" slot.

    Sizing contract (UI-SPEC):
      - Minimum 44 × 44 px touch targets everywhere.
      - 64 px × 64 px on phone (h-16 w-16).
      - 56 px × 56 px on desktop (sm:h-14 sm:w-14).
--}}

@php
    $digitAnnouncements = array_map(
        static fn (int $count): string => Lang::choice('auth::lock_screen.digits_entered', $count, ['count' => $count]),
        range(0, 10),
    );
@endphp
{{-- Dot display — aria-live so screen readers announce digit count changes --}}
<div
    class="flex justify-center gap-2 py-3"
    role="status"
    aria-live="polite"
    x-bind:aria-label="@js($digitAnnouncements)[pin.length]"
>
    <template x-for="i in 10" :key="i">
        <span
            class="h-3 w-3 rounded-full transition-colors duration-150 motion-reduce:transition-none"
            x-bind:class="i <= pin.length
                ? 'bg-slate-900 dark:bg-slate-100'
                : 'bg-slate-200 dark:bg-slate-700'"
        ></span>
    </template>
</div>

{{-- Backoff / flash label — assertive so screen readers interrupt for errors --}}
@if ($flashMessage !== '')
    <div
        class="text-center text-sm text-rose-600 dark:text-rose-400 py-1"
        aria-live="assertive"
        role="alert"
    >
        {{ $flashMessage }}
    </div>
@else
    <div class="py-1" aria-live="assertive"></div>
@endif

{{-- PIN pad grid --}}
<div class="grid grid-cols-3 gap-2" role="group" aria-label="{{ Lang::get('auth::lock_screen.pad_label') }}">

    {{-- Digits 1–9 --}}
    @foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $digit)
        <button
            type="button"
            x-on:click="press('{{ $digit }}')"
            aria-label="{{ Lang::get('auth::lock_screen.digit_aria', ['digit' => $digit]) }}"
            class="flex h-16 w-full sm:h-14 items-center justify-center rounded-xl bg-slate-100 dark:bg-slate-800
                   text-xl font-semibold text-slate-900 dark:text-slate-100
                   transition-colors duration-[80ms] motion-reduce:transition-none
                   hover:bg-slate-200 dark:hover:bg-slate-700
                   active:bg-slate-300 dark:active:bg-slate-600
                   focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:focus-visible:ring-slate-100 focus-visible:ring-offset-2"
        >
            {{ $digit }}
        </button>
    @endforeach

    {{-- Bottom row: Backspace | 0 | Confirm --}}
    <button
        type="button"
        x-on:click="back()"
        aria-label="{{ Lang::get('auth::lock_screen.backspace_aria') }}"
        class="flex h-16 w-full sm:h-14 items-center justify-center rounded-xl bg-slate-100 dark:bg-slate-800
               text-slate-600 dark:text-slate-400
               transition-colors duration-[80ms] motion-reduce:transition-none
               hover:bg-slate-200 dark:hover:bg-slate-700
               active:bg-slate-300 dark:active:bg-slate-600
               focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:focus-visible:ring-slate-100 focus-visible:ring-offset-2"
    >
        {{-- Backspace icon (inline SVG, decorative) --}}
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
             class="h-5 w-5" aria-hidden="true">
            <path d="M21 4H8l-7 8 7 8h13a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2z"/>
            <line x1="18" y1="9" x2="12" y2="15"/>
            <line x1="12" y1="9" x2="18" y2="15"/>
        </svg>
    </button>

    <button
        type="button"
        x-on:click="press('0')"
        aria-label="{{ Lang::get('auth::lock_screen.digit_aria', ['digit' => 0]) }}"
        class="flex h-16 w-full sm:h-14 items-center justify-center rounded-xl bg-slate-100 dark:bg-slate-800
               text-xl font-semibold text-slate-900 dark:text-slate-100
               transition-colors duration-[80ms] motion-reduce:transition-none
               hover:bg-slate-200 dark:hover:bg-slate-700
               active:bg-slate-300 dark:active:bg-slate-600
               focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:focus-visible:ring-slate-100 focus-visible:ring-offset-2"
    >
        0
    </button>

    <button
        type="button"
        x-on:click="submitPin()"
        aria-label="{{ Lang::get('auth::lock_screen.ok_aria') }}"
        class="flex h-16 w-full sm:h-14 items-center justify-center rounded-xl bg-slate-900 dark:bg-slate-100
               text-white dark:text-slate-900
               text-sm font-semibold
               transition-colors duration-[80ms] motion-reduce:transition-none
               hover:bg-slate-700 dark:hover:bg-slate-200
               active:bg-slate-600 dark:active:bg-slate-300
               focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:focus-visible:ring-slate-100 focus-visible:ring-offset-2"
    >
        {{ Lang::get('auth::lock_screen.ok') }}
    </button>

</div>
