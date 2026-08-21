{{--
    PWA head block. Shared by the main app and
    lock shells; the dev-console and wizard shells omit it.
    - Manifest link: tells browsers/OS install affordance where to find
      the app name, icons, and display mode (standalone).
    - Dual theme-color: light #f8fafc and dark #020617 so the browser
      chrome (address bar, status bar) matches the app palette in both
      colour schemes (UI-SPEC §13).
    - apple-touch-icon: iOS Safari uses this for the home-screen icon
      when the user chooses "Add to Home Screen". Must be opaque
      (180×180 with #f8fafc background) — iOS ignores transparency.
--}}
<link rel="manifest" href="/site.webmanifest" />
{{-- Same purpose as the `color-scheme` property in app.css, but effective
     on the frames before any stylesheet has loaded. --}}
<meta name="color-scheme" content="light dark" />
<meta name="theme-color" content="#f8fafc" media="(prefers-color-scheme: light)" />
<meta name="theme-color" content="#020617" media="(prefers-color-scheme: dark)" />
<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png" />
{{-- Declared, so the browser stops falling back to its default request for
     /favicon.ico — which this app has never shipped, so every page load
     answered a 404 that showed up in the console and the dev log. --}}
<link rel="icon" type="image/png" sizes="192x192" href="/icons/icon-192.png" />
<link rel="icon" type="image/png" sizes="512x512" href="/icons/icon-512.png" />
