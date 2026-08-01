@use('Modules\Core\Public\Support\Lang')
{{-- Settings → Shared merchant list panel.

     Two-column 280px + 1fr grid (collapses to a single column on
     narrow viewports). The meta-side renders the "About the shared
     list" headline + stats; the body-side renders three toggle rows.
     Toggle 3 ships disabled with a version-agnostic inline note —
     no invented release number; the note flips to an active state
     when the auto-update plumbing lands. --}}

<div class="settings-section grid grid-cols-1 gap-6 border-b border-slate-200 py-6 md:grid-cols-[280px_1fr] dark:border-slate-700">
    <aside class="meta-side rounded-md bg-slate-50 px-5 py-5 text-sm text-slate-700 dark:bg-slate-900 dark:text-slate-300">
        <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('community::settings.about_heading') }}</h2>
        <p class="mt-2 text-sm">
            {{ Lang::get('community::settings.about_body') }}
        </p>
        <dl class="mt-4 grid grid-cols-2 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
            <dt>{{ Lang::get('community::settings.mappings') }}</dt>
            <dd class="text-right" style="font-variant-numeric: tabular-nums;">{{ $mappingsCount }}</dd>
            <dt>{{ Lang::get('community::settings.contributors') }}</dt>
            <dd class="text-right" style="font-variant-numeric: tabular-nums;">{{ $contributorCount }}</dd>
        </dl>
    </aside>

    <div class="body-side space-y-1">
        <div class="toggle-row flex items-start justify-between gap-4 border-b border-slate-200 py-3 dark:border-slate-700">
            <div class="flex-1">
                <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ Lang::get('community::settings.use_shared_list.title') }}</p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    {{ Lang::get('community::settings.use_shared_list.help') }}
                </p>
            </div>
            <label for="toggle-use-shared-list" class="cursor-pointer">
                <span class="sr-only">{{ Lang::get('community::settings.use_shared_list.title') }}</span>
                <input
                    type="checkbox"
                    id="toggle-use-shared-list"
                    wire:change="toggleUseSharedList"
                    @checked($useSharedList)
                    class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-600 dark:border-slate-600 dark:text-emerald-500"
                />
            </label>
        </div>

        <div class="toggle-row flex items-start justify-between gap-4 border-b border-slate-200 py-3 dark:border-slate-700">
            <div class="flex-1">
                <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ Lang::get('community::settings.offer_to_contribute.title') }}</p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    {{ Lang::get('community::settings.offer_to_contribute.help') }}
                </p>
            </div>
            <label for="toggle-offer-to-contribute" class="cursor-pointer">
                <span class="sr-only">{{ Lang::get('community::settings.offer_to_contribute.title') }}</span>
                <input
                    type="checkbox"
                    id="toggle-offer-to-contribute"
                    wire:change="toggleOfferToContribute"
                    @checked($offerToContribute)
                    class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-600 dark:border-slate-600 dark:text-emerald-500"
                />
            </label>
        </div>

        <div class="toggle-row flex items-start justify-between gap-4 py-3">
            <div class="flex-1">
                <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ Lang::get('community::settings.update_on_updates.title') }}</p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    {{ Lang::get('community::settings.update_on_updates.help') }}
                </p>
                <p class="mt-1 text-xs italic text-slate-400 dark:text-slate-500" data-testid="toggle-update-note">
                    {{ Lang::get('community::settings.update_on_updates.note') }}
                </p>
            </div>
            <label for="toggle-update-on-updates" class="cursor-not-allowed">
                <span class="sr-only">{{ Lang::get('community::settings.update_on_updates.title') }}</span>
                <input
                    type="checkbox"
                    id="toggle-update-on-updates"
                    disabled
                    @checked($updateOnAppUpdates)
                    class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-600 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-600 dark:text-emerald-500"
                />
            </label>
        </div>
    </div>
</div>
