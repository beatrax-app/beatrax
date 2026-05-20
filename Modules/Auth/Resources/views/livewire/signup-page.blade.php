<div class="min-h-screen flex items-center justify-center bg-white">
    <div class="w-full max-w-md mx-auto px-6 space-y-6">
        <header class="space-y-1">
            <h1 class="text-3xl font-semibold text-slate-900 tracking-tight">Welcome to diederik</h1>
            <p class="text-sm text-slate-500">Create the first account on this device. The first account becomes the owner.</p>
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
                <label for="password" class="block text-sm text-slate-900">Password</label>
                <input
                    type="password"
                    id="password"
                    wire:model="password"
                    autocomplete="new-password"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
                />
            </div>

            <div class="space-y-1">
                <label for="password-confirmation" class="block text-sm text-slate-900">Confirm password</label>
                <input
                    type="password"
                    id="password-confirmation"
                    wire:model="passwordConfirmation"
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
                Create the first account
            </button>
        </form>
    </div>
</div>
