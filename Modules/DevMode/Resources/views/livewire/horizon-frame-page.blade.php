<div class="p-8 space-y-4" data-testid="horizon-frame-page">
    <header class="space-y-1">
        <h1 class="text-xl font-semibold text-[var(--color-text)]">Horizon</h1>
        <p class="text-sm text-[var(--color-text-muted)]">Embedded Horizon dashboard. Available only on dev boxes where the package is installed.</p>
    </header>

    <div class="rounded-md border border-[var(--color-border)]" style="background: var(--color-surface);">
        <iframe
            src="/horizon"
            class="w-full h-[80vh] border-0 rounded-md"
            title="Horizon dashboard"
            sandbox="allow-same-origin allow-scripts allow-forms"
            referrerpolicy="same-origin"
        ></iframe>
    </div>
</div>
