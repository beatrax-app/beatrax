@use('Modules\Core\Public\Support\Lang')
{{-- D-20 / UI-SPEC §19: overflow-x-auto wrapper ensures the iframe does not
     force page-level horizontal overflow at phone width. The iframe keeps
     w-full so it fills the available column; the wrapper handles the scroll. --}}
<div class="p-8 space-y-4 overflow-x-auto" data-testid="horizon-frame-page">
    <header class="space-y-1">
        <h1 class="text-xl font-semibold text-[var(--color-text)]">{{ Lang::get('dev::horizon.heading') }}</h1>
        <p class="text-sm text-[var(--color-text-muted)]">{{ Lang::get('dev::horizon.subtitle') }}</p>
    </header>

    <div class="rounded-md border border-[var(--color-border)]" style="background: var(--color-surface);">
        <iframe
            src="/horizon"
            class="w-full h-[80vh] border-0 rounded-md"
            title="{{ Lang::get('dev::horizon.iframe_title') }}"
            sandbox="allow-same-origin allow-scripts allow-forms"
            referrerpolicy="same-origin"
        ></iframe>
    </div>
</div>
