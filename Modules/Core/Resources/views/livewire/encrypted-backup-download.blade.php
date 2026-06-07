<div>
    @if (! $sqliteOnly)
        <p class="text-sm text-slate-500 dark:text-slate-400">
            Encrypted backups are available on the desktop (SQLite) build. On a server
            database, use your database's own backup tooling.
        </p>
    @else
        <p class="text-sm text-slate-500 dark:text-slate-400">
            Download a passphrase-encrypted copy of your whole database — safe to keep on
            an external drive or in cloud storage, because it is unreadable without the
            passphrase (quantum-safe XChaCha20-Poly1305 + Argon2id).
        </p>

        <form wire:submit="download" class="mt-3 space-y-3">
            <div class="space-y-1">
                <label for="backup-passphrase" class="block text-sm text-slate-900 dark:text-slate-100">Passphrase</label>
                <input
                    type="password"
                    id="backup-passphrase"
                    wire:model="passphrase"
                    autocomplete="new-password"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:focus-visible:ring-slate-100"
                />
            </div>
            <div class="space-y-1">
                <label for="backup-passphrase-confirm" class="block text-sm text-slate-900 dark:text-slate-100">Confirm passphrase</label>
                <input
                    type="password"
                    id="backup-passphrase-confirm"
                    wire:model="confirmPassphrase"
                    autocomplete="new-password"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:focus-visible:ring-slate-100"
                />
            </div>

            <p class="text-xs text-amber-700 dark:text-amber-400">
                Keep the passphrase safe — there is no way to recover the backup without it.
            </p>

            @if ($error !== '')
                <p class="text-sm text-rose-600 dark:text-rose-500">{{ $error }}</p>
            @endif

            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="download"
                class="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 hover:bg-slate-50 disabled:opacity-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:hover:bg-slate-800"
            >
                <span wire:loading.remove wire:target="download">Download encrypted backup</span>
                <span wire:loading wire:target="download">Preparing…</span>
            </button>
        </form>
    @endif
</div>
