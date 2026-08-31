@use('Modules\Core\Public\Support\Lang')
{{--
    Global toast stack. Mounted by the main app and dev-console shells so the
    same $this->dispatch('toast', message: '...') reaches a visible surface no
    matter which layout the current request resolved through. Listens for the
    window `toast` event, renders each message for 5s, and dismisses on click.
    role="status" carries the implicit aria-live="polite" / aria-atomic="true"
    a polite live region needs.

    An undo-able toast carries the dispatching component's own id, because a
    browser event says nothing about where it came from and this host is
    mounted by the layout rather than by the component that raised it.
    Livewire.find() is what turns that id back into the component whose undo
    method the button calls. Before this, every Undo in the app was dispatched
    into a host that read `detail.message` and nothing else.
--}}
<div
    x-data="{
        toasts: [],
        push(detail) {
            const id = Date.now() + Math.random();
            this.toasts.push({
                id,
                message: (detail && detail.message) || '',
                componentId: (detail && detail.componentId) || null,
                undoAction: (detail && detail.undoAction) || null,
                undoPayload: detail ? detail.undoPayload : null,
            });
            setTimeout(() => { this.toasts = this.toasts.filter(t => t.id !== id); }, 5000);
        },
        dismiss(id) { this.toasts = this.toasts.filter(t => t.id !== id); },
        undo(t) {
            const target = t.componentId && window.Livewire ? window.Livewire.find(t.componentId) : null;
            if (target && t.undoAction) {
                target.call(t.undoAction, t.undoPayload);
            }
            this.dismiss(t.id);
        },
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
            class="pointer-events-auto flex cursor-pointer items-center gap-3 rounded-md bg-slate-900 px-4 py-3 text-sm text-white shadow-lg ring-1 ring-slate-800 dark:bg-slate-100 dark:text-slate-900 dark:ring-slate-300"
            role="alert"
            data-testid="toast"
        >
            <span class="min-w-0 flex-1" x-text="t.message"></span>
            <button
                type="button"
                x-show="t.undoAction"
                x-on:click.stop="undo(t)"
                class="-my-3 shrink-0 self-stretch px-2 py-3 text-sm font-semibold underline underline-offset-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-slate-900 dark:focus-visible:ring-slate-900 dark:focus-visible:ring-offset-slate-100"
                data-testid="toast-undo"
            >{{ Lang::get('core::components.toast_undo') }}</button>
        </div>
    </template>
</div>
