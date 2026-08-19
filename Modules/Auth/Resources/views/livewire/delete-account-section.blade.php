@use('Modules\Core\Public\Support\Lang')
{{--
    Account-deletion section for the Core settings page, mounted via
    @livewire('auth.delete-account-section').

    The paired-device paragraph is not decoration. Deleting here reaches this
    device only, and a screen that let someone believe otherwise would be worse
    than having no delete button at all.
--}}
<div data-testid="delete-account-section">
    <p class="text-sm text-slate-500 dark:text-slate-400">
        {{ Lang::get('auth::delete_account.body') }}
    </p>

    <p class="mt-2 text-xs text-[var(--color-text-muted)]">
        {{ Lang::get('auth::delete_account.removes') }}
    </p>

    <div
        class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs text-amber-700 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300"
        role="note"
        data-testid="delete-account-devices"
    >
        <p class="font-semibold">{{ Lang::get('auth::delete_account.devices_heading') }}</p>
        <p class="mt-1">
            @if ($pairedDeviceNames === [])
                {{ Lang::get('auth::delete_account.devices_none') }}
            @else
                {{ Lang::get('auth::delete_account.devices_body', ['devices' => implode(', ', $pairedDeviceNames)]) }}
            @endif
        </p>
        @if ($successorUsername !== null)
            <p class="mt-1" data-testid="delete-account-successor">
                {{ Lang::get('auth::delete_account.successor', ['username' => $successorUsername]) }}
            </p>
        @endif
    </div>

    @if ($failure !== null)
        <p class="mt-3 text-sm text-rose-600 dark:text-rose-400" role="alert" data-testid="delete-account-failure">
            {{ $failure }}
        </p>
    @endif

    @unless ($confirming)
        <button
            type="button"
            wire:click="startConfirm"
            class="mt-3 inline-flex min-h-[44px] items-center rounded-md border border-rose-300 bg-white px-3 py-1.5 text-sm font-medium text-rose-700
                   hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2
                   dark:border-rose-900 dark:bg-slate-900 dark:text-rose-300 dark:hover:bg-slate-800"
            data-testid="start-delete-account"
        >{{ Lang::get('auth::delete_account.start') }}</button>
    @else
        <div class="mt-3 space-y-3 rounded-xl border border-rose-200 bg-rose-50 p-4 dark:border-rose-900 dark:bg-rose-950">
            <p class="text-sm font-semibold text-rose-800 dark:text-rose-200">
                {{ Lang::get('auth::delete_account.confirm_heading') }}
            </p>
            <p class="text-xs text-rose-700 dark:text-rose-300">
                {{ Lang::get('auth::delete_account.confirm_body') }}
            </p>

            <label class="block text-xs font-medium text-rose-800 dark:text-rose-200" for="delete-account-password">
                {{ Lang::get('auth::delete_account.password_label') }}
            </label>
            <input
                id="delete-account-password"
                type="password"
                autocomplete="current-password"
                wire:model="password"
                class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900
                       focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600
                       dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                data-testid="delete-account-password"
            />
            @error('password')
                <p class="text-xs text-rose-700 dark:text-rose-300" role="alert">{{ $message }}</p>
            @enderror

            <div class="flex gap-3">
                <button
                    type="button"
                    wire:click="deleteAccount"
                    wire:loading.attr="disabled"
                    class="min-h-[44px] flex-1 rounded-md bg-rose-600 px-4 py-2 text-sm font-semibold text-white
                           hover:bg-rose-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2"
                    data-testid="confirm-delete-account"
                >{{ Lang::get('auth::delete_account.confirm') }}</button>
                <button
                    type="button"
                    wire:click="cancel"
                    class="min-h-[44px] flex-1 rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-900
                           hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                           dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700"
                    data-testid="cancel-delete-account"
                >{{ Lang::get('auth::delete_account.cancel') }}</button>
            </div>
        </div>
    @endunless
</div>
