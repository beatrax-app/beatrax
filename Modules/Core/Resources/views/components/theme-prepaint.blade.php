@props(['enabled' => false])
{{--
    Pre-paint theme script. Runs synchronously in <head> before the body
    renders, so a `system`-theme user never sees a flash of the wrong
    theme. Reads the OS-level `prefers-color-scheme` media query — a fixed,
    app-authored snippet with no dynamic interpolation.

    Emitted whenever the OsThemeSignal returned null (no explicit OS
    preference, or the desktop binding is absent in local dev) — the
    script's `matchMedia` read is the authoritative source in that case.
    When the bundle resolved an explicit `light` / `dark`, the server-side
    `dark` class is already correct and the script would only undo / re-do
    the same answer.
--}}
@if ($enabled)
    {{-- The class below is applied by script, which runs only AFTER the root
         element's own `bg-white` class has been parsed — so a dark device
         could paint a full white frame first. On the lock screen that flash is
         the whole page. This style needs no script and no class, so it wins
         the race outright; it is scoped to $enabled, meaning the theme is
         `system` and the OS preference is genuinely authoritative.
         The root tag is spelled out rather than written literally: the HTML
         analyser does not parse Blade comments, and read a mention of it here
         as a real, doctype-less document. --}}
    {{-- Guarded the way the veil rule in app.css already is. Unguarded, this
         wins over the bg-white utility whenever the OS is dark, so a reader on
         a dark device who chose Light got a black page with white cards on it
         — the heading was near-black text on a near-black background. --}}
    <style>
        @media (prefers-color-scheme: dark) {
            html:not(.light), html:not(.light) body, html:not(.light) .beatrax-shell {
                background-color: #020617;
            }
        }
    </style>
    <script nonce="{{ Vite::cspNonce() }}">
        (function () {
            try {
                if (document.documentElement.classList.contains('light')) {
                    document.documentElement.classList.remove('dark');
                } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    document.documentElement.classList.add('dark');
                } else {
                    document.documentElement.classList.remove('dark');
                }
            } catch (e) {}
        })();
    </script>
@endif
