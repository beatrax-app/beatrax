@use('Modules\Core\Public\Support\Lang')
@inject('container', \Illuminate\Contracts\Container\Container::class)
@props(['status', 'title', 'body'])
@php
    // Same chrome the lock screen resolves — theme and language — so a failure
    // still looks like the app the user is standing in rather than like the
    // framework underneath it.
    //
    // Resolving chrome touches the container and the user's preferences, which
    // is fine for the failures that actually reach these views (a mistyped
    // route, an expired session, a page that is genuinely gone). If the
    // resolution itself throws — a broken database during a 500 — Laravel
    // falls back to its own minimal page, which is exactly today's behaviour,
    // so this cannot make a catastrophic failure worse than it already is.
    $chrome = $container->make(\Modules\Core\Public\Support\AppChromeResolver::class)->resolve();
@endphp
{{--
    In-app failure page: state what happened and what to do next.

    The app ships as a desktop and mobile shell, so there is no address bar,
    no reload button and no browser back to fall back on. A raw framework
    status page is therefore a dead end: it names a number, offers no route
    onward, and looks like something other than the product. With APP_DEBUG on
    it is worse — the whole stack trace is put in front of the user, which
    this page must never do.

    Every status gets a sentence about what happened and a way back.
--}}
<!doctype html>
<html
    lang="{{ $chrome->locale }}"
    class="beatrax-shell text-slate-900 dark:text-slate-100 {{ $chrome->isDark ? 'dark' : '' }}"
    style="font-feature-settings: 'tnum';"
>
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content" />
        <title>{{ $title }} · Beatrax</title>
        <x-core::theme-prepaint :enabled="$chrome->needsPrePaintScript" />
        <x-core::head-assets :chrome="$chrome" />
    </head>
    <body
        class="beatrax-shell antialiased text-slate-900 dark:text-slate-100"
        style="font-family: system-ui, -apple-system, sans-serif;"
    >
        <main class="bx-error">
            <p class="bx-error-status" aria-hidden="true">{{ $status }}</p>
            <h1 class="bx-error-title">{{ $title }}</h1>
            <p class="bx-error-body">{{ $body }}</p>

            {{-- A real destination, not history.back(): the shell has no back
                 affordance of its own, and the page that led here may be the
                 one that is broken. --}}
            <a class="bx-error-action" href="{{ url('/') }}">
                {{ Lang::get('core::errors.back') }}
            </a>
        </main>
    </body>
</html>
