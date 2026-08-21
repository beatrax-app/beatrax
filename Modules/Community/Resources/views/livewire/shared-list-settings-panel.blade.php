@use('Modules\Core\Public\Support\Lang')
{{-- Community → Shared merchant list panel.

     Renders flat: the Community page provides the card, so this draws no
     box of its own. The stats ride under the intro as a caption; the body
     side renders three toggle rows.
     Toggle 3 ships disabled with a version-agnostic inline note —
     no invented release number; the note flips to an active state
     when the auto-update plumbing lands. --}}

<div class="space-y-6">
    {{-- No card of its own: this panel sits inside the Community page's card,
         and wrapping the intro in a second grey box read as a box in a box.
         The stats sit inline as a caption rather than in a sidebar. --}}
    <div class="space-y-2">
        <p class="max-w-prose text-sm text-slate-500 dark:text-slate-400">
            {{ Lang::get('community::settings.about_body') }}
        </p>
    </div>

    <div class="body-side space-y-1">
        <div class="toggle-row flex items-start justify-between gap-4 border-b border-slate-200 py-3 dark:border-slate-700">
            <div class="flex-1">
                <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ Lang::get('community::settings.use_shared_list.title') }}</p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    {{ Lang::get('community::settings.use_shared_list.help') }}
                </p>
            </div>
            <x-core::switch
                id="toggle-use-shared-list"
                :on="$useSharedList"
                :label="Lang::get('community::settings.use_shared_list.title')"
                wire:click="toggleUseSharedList"
            />
        </div>

        <div class="toggle-row flex items-start justify-between gap-4 border-b border-slate-200 py-3 dark:border-slate-700">
            <div class="flex-1">
                <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ Lang::get('community::settings.offer_to_contribute.title') }}</p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    {{ Lang::get('community::settings.offer_to_contribute.help') }}
                </p>
            </div>
            <x-core::switch
                id="toggle-offer-to-contribute"
                :on="$offerToContribute"
                :label="Lang::get('community::settings.offer_to_contribute.title')"
                wire:click="toggleOfferToContribute"
            />
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
            {{-- No wire:click: nothing to call until the auto-update plumbing lands. --}}
            <x-core::switch
                id="toggle-update-on-updates"
                :on="$updateOnAppUpdates"
                :label="Lang::get('community::settings.update_on_updates.title')"
                disabled
                class="disabled:cursor-not-allowed disabled:opacity-50"
            />
        </div>
    </div>
</div>
