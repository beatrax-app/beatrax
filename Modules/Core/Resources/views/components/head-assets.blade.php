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

    The same choice is published on the root element, which is what the page
    re-asserts from when the operating system's theme changes under it -- see
    applyThemeChoice() in resources/js/app.js. The key alone was not enough:
    the Android WebView came back with localStorage empty.
--}}
@if ($chrome !== null)
    <script nonce="{{ Vite::cspNonce() }}">
        (function () {
            // On the root as well as in Flux's key, because the key does not
            // survive. Read over the DevTools protocol on a Galaxy S24 Ultra
            // after a night-mode change: localStorage length 0, no
            // flux.appearance, and <html class="... light dark"> -- Flux had
            // fallen back to `system` and painted the page dark under a Theme
            // toggle still reading Light. An attribute the server writes is
            // there for as long as the document is.
            document.documentElement.dataset.themeChoice = @js($chrome->theme);

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
