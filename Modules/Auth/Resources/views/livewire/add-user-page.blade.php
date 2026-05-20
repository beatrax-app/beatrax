<div class="mx-auto max-w-md px-8 py-12 space-y-12">
    <header class="space-y-1">
        <h1 class="text-3xl font-semibold text-slate-900 tracking-tight">Add a user</h1>
        <p class="text-sm text-slate-500">Create an account for someone else on this machine. They will be asked to set their own password the first time they sign in.</p>
    </header>

    <form wire:submit="submit" class="space-y-4">
        <div class="space-y-1">
            <label for="username" class="block text-sm text-slate-900">Username</label>
            <input
                type="text"
                id="username"
                wire:model="username"
                autocomplete="off"
                autofocus
                class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
            />
        </div>

        <div class="space-y-1">
            <label for="initial-password" class="block text-sm text-slate-900">Initial password</label>
            <input
                type="password"
                id="initial-password"
                wire:model="initialPassword"
                autocomplete="new-password"
                class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
            />
            <p class="text-xs text-slate-500">Type a password they can read aloud or type once. They will replace it on first login.</p>
        </div>

        <div class="space-y-1">
            <label for="initial-password-confirmation" class="block text-sm text-slate-900">Confirm initial password</label>
            <input
                type="password"
                id="initial-password-confirmation"
                wire:model="initialPasswordConfirmation"
                autocomplete="new-password"
                class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
            />
        </div>

        @if ($flashMessage !== '')
            <p class="text-sm text-slate-700">{{ $flashMessage }}</p>
        @endif

        <button
            type="submit"
            class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-md py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2"
        >
            Set initial password
        </button>
    </form>
</div>
