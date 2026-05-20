<div class="min-h-screen flex items-center justify-center bg-white">
    <div class="w-full max-w-sm px-6 space-y-6">
        <header class="space-y-1">
            <h1 class="text-3xl font-semibold text-slate-900 tracking-tight">Reset your password</h1>
            <p class="text-sm text-slate-500">Type your username, one of your recovery codes, and a new password. The code will be marked used.</p>
        </header>

        <form wire:submit="submit" class="space-y-4">
            <div class="space-y-1">
                <label for="username" class="block text-sm text-slate-900">Username</label>
                <input
                    type="text"
                    id="username"
                    wire:model="username"
                    autocomplete="username"
                    autofocus
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
                />
            </div>

            <div class="space-y-1">
                <label for="recovery-code" class="block text-sm text-slate-900">Recovery code</label>
                <input
                    type="text"
                    id="recovery-code"
                    wire:model="recoveryCode"
                    autocomplete="off"
                    placeholder="A2BJ-XK9M-PQ7N-RX4F-V8HD"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
                />
                <p class="text-xs text-slate-500">5 groups of 4 characters, like A2BJ-XK9M-PQ7N-RX4F-V8HD.</p>
            </div>

            <div class="space-y-1">
                <label for="new-password" class="block text-sm text-slate-900">New password</label>
                <input
                    type="password"
                    id="new-password"
                    wire:model="newPassword"
                    autocomplete="new-password"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
                />
            </div>

            <div class="space-y-1">
                <label for="new-password-confirmation" class="block text-sm text-slate-900">Confirm new password</label>
                <input
                    type="password"
                    id="new-password-confirmation"
                    wire:model="newPasswordConfirmation"
                    autocomplete="new-password"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
                />
            </div>

            @if ($flashMessage !== '')
                <p class="text-sm text-rose-600">{{ $flashMessage }}</p>
            @endif

            <button
                type="submit"
                class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-md py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2"
            >
                Save new password
            </button>
        </form>

        <p class="text-sm">
            <a
                href="/login"
                class="text-slate-500 underline underline-offset-2 hover:text-slate-900"
            >
                Back to sign in
            </a>
        </p>
    </div>
</div>
