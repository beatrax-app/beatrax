@props(['chrome' => null])
{{--
    Shared front-end asset includes for every page shell: the Vite bundle
    (app CSS + JS), Livewire's styles, and Flux's appearance block. Kept in
    one component so the four layouts cannot drift on which assets they pull.
--}}
@vite(['resources/css/app.css', 'resources/js/app.js'])
@livewireStyles
{{--
    Flux keeps its appearance in localStorage and defaults to `system` when
    the key is absent, then applies that on every load — so it re-decided the
    theme from the OS after the server had already rendered the user's stored
    choice. A reader who picked Light on a dark phone got `class="light dark"`
    and a dark page; one who picked Dark on a light phone had the class taken
    off again. The preference lives in the database here, so the key has to be
    seeded from it before Flux reads it, and this script must therefore stay
    above @fluxAppearance.
--}}
@if ($chrome !== null)
    <script nonce="{{ Vite::cspNonce() }}">
        (function () {
            try {
                @if ($chrome->theme === 'system')
                    window.localStorage.removeItem('flux.appearance');
                @else
                    window.localStorage.setItem('flux.appearance', @js($chrome->theme));
                @endif
            } catch (e) {}
        })();
    </script>
@endif
@fluxAppearance(['nonce' => Vite::cspNonce()])
