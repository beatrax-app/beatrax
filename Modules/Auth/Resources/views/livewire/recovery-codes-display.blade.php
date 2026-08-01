@use('Modules\Core\Public\Support\Lang')
<div class="min-h-screen bg-white py-12 dark:bg-slate-950">
    <div class="max-w-xl mx-auto px-6 space-y-6">
        <header class="space-y-1">
            <h1 class="text-3xl font-semibold text-slate-900 tracking-tight dark:text-slate-100">{{ Lang::get('auth::recovery_codes.title') }}</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('auth::recovery_codes.subtitle') }}</p>
        </header>

        <div aria-live="polite" class="grid grid-cols-2 gap-3">
            @foreach ($codes as $code)
                <div class="bg-slate-50 border border-slate-200 rounded-md px-4 py-3 text-lg font-semibold font-mono tabular-nums tracking-wider text-slate-900 dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700" style="font-variant-numeric: tabular-nums;">
                    {{ $code }}
                </div>
            @endforeach
        </div>

        <div class="space-y-2">
            <button
                type="button"
                wire:click="download"
                class="rounded-md bg-slate-100 px-3 py-2 text-sm font-medium text-slate-900 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:bg-slate-700 dark:bg-slate-800 dark:text-slate-100"
            >
                {{ Lang::get('auth::recovery_codes.download') }}
            </button>

            @if ($downloadShown)
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('auth::recovery_codes.saved_as', ['username' => $username]) }}</p>
            @endif
        </div>

        <label class="flex items-start gap-2">
            <input type="checkbox" wire:model.live="confirmed" class="mt-1 rounded border-slate-300 dark:border-slate-600">
            <span class="text-sm text-slate-700 dark:text-slate-300">{{ Lang::get('auth::recovery_codes.confirm') }}</span>
        </label>

        <button
            type="button"
            wire:click="continueAfterSave"
            @disabled(! $confirmed)
            aria-disabled="{{ $confirmed ? 'false' : 'true' }}"
            @class([
                'w-full rounded-md py-2 text-sm font-medium text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:focus-visible:ring-emerald-500',
                'bg-emerald-600 hover:bg-emerald-700 dark:bg-emerald-500 dark:hover:bg-emerald-400' => $confirmed,
                'bg-emerald-600/50 cursor-not-allowed dark:bg-emerald-500/40' => ! $confirmed,
            ])
        >
            {{ Lang::get('auth::recovery_codes.continue') }}
        </button>
    </div>
</div>
