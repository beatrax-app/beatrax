{{--
    Global toast stack. Mounted by the main app and dev-console shells so the
    same $this->dispatch('toast', message: '...') reaches a visible surface no
    matter which layout the current request resolved through. Listens for the
    window `toast` event, renders each message for 5s, and dismisses on click.
    role="status" carries the implicit aria-live="polite" / aria-atomic="true"
    a polite live region needs.
--}}
<div
    x-data="{
        toasts: [],
        push(detail) {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, message: (detail && detail.message) || '' });
            setTimeout(() => { this.toasts = this.toasts.filter(t => t.id !== id); }, 5000);
        },
        dismiss(id) { this.toasts = this.toasts.filter(t => t.id !== id); },
    }"
    x-on:toast.window="push($event.detail)"
    class="pointer-events-none fixed bottom-4 right-4 z-[10000] flex w-[min(380px,calc(100vw-2rem))] flex-col-reverse gap-2"
    role="status"
    aria-live="polite"
    data-testid="toast-host"
>
    <template x-for="t in toasts" :key="t.id">
        <div
            x-on:click="dismiss(t.id)"
            class="pointer-events-auto cursor-pointer rounded-md bg-slate-900 px-4 py-3 text-sm text-white shadow-lg ring-1 ring-slate-800 dark:bg-slate-100 dark:text-slate-900 dark:ring-slate-300"
            role="alert"
            data-testid="toast"
            x-text="t.message"
        ></div>
    </template>
</div>
