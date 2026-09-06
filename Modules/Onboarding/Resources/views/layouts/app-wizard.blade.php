@use('Modules\Core\Public\Support\Brand')
@use('Modules\Core\Public\Support\Lang')
@inject('container', \Illuminate\Contracts\Container\Container::class)
@php
    // First-run setup wizard shell — body-only chrome (no sidebar, alerts
    // banner, or palette): the calm setup page is the entire focal point.
    // It carries the same dark/lang chrome as the app layout, resolved once.
    $chrome = $container->make(\Modules\Core\Public\Support\AppChromeResolver::class)->resolve();
@endphp
<!doctype html>
<html
    lang="{{ $chrome->locale }}"
    class="bg-white text-slate-900 dark:bg-slate-950 dark:text-slate-100 {{ $chrome->rootThemeClass() }}"
    style="font-feature-settings: 'tnum';"
>
    <head>
        <meta charset="utf-8" />
        {{-- viewport-fit=cover is what makes iOS populate env(safe-area-inset-*);
             without it the wizard chrome draws under the notch. The app and lock
             layouts already carry it — this shell was the only one that did not.
             Android does not use env() at all: its shell injects --inset-* onto
             :root, which --safe-* reads through max().

             interactive-widget=resizes-content is what keeps the soft keyboard
             from offsetting the visual viewport alone and leaving this shell's
             sticky chrome anchored off-screen. The wizard takes typed amounts
             on its starting-balance cards, so it meets the keyboard too. --}}
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content" />
        <meta name="csrf-token" content="{{ csrf_token() }}" />

        {{-- The wizard has its own layout, and the first import happens here:
             the signal was added to the two shared layouts and missed this
             one, so the encoded transport never engaged where it was needed
             most. UploadSignalArchTest holds every layout to it now. --}}
        @if (($beatraxEncodedUploads ?? false) === true)
            <meta name="beatrax-upload-transport" content="base64" />
        @endif
        <title>{{ $title ?? Lang::get('onboarding::wizard.page_title').Brand::TITLE_SUFFIX }}</title>
        <x-core::theme-prepaint :enabled="$chrome->needsPrePaintScript" />
        <x-core::head-assets :chrome="$chrome" />
    </head>
    <body
        class="antialiased bg-white text-slate-900 dark:bg-slate-950 dark:text-slate-100"
        style="font-family: system-ui, -apple-system, sans-serif;"
    >
        <div class="wiz-page">
            {{ $slot }}
        </div>
        @livewire('email-scan.oauth-client-wizard-modal')
        @livewireScripts
        @fluxScripts
    </body>
</html>
