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
    <script>
        (function () {
            try {
                if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    document.documentElement.classList.add('dark');
                } else {
                    document.documentElement.classList.remove('dark');
                }
            } catch (e) {}
        })();
    </script>
@endif
