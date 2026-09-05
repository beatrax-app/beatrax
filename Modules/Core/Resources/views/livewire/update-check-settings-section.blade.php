@use('Modules\Core\Public\Support\Lang')
{{--
    The update check and its off switch. Extracted out of the Settings page
    whole, the way the tax and recovery-code sections were: the page was one
    method over the analyser's class ceiling, and this is a section with its
    own state rather than another preference on a shared form.
--}}
<div class="space-y-3">
    @if ($onPhone)
        <p class="text-sm text-slate-500 dark:text-slate-400">
            {{ Lang::get('core::settings.about_updates.body_phone') }}
        </p>
    @else
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
                <span class="block text-sm text-[var(--color-text)]">{{ Lang::get('core::settings.about_updates.check_label') }}</span>
                <p id="update-check-help" class="mt-1 text-xs text-[var(--color-text-muted)]">
                    {{ Lang::get($enabled ? 'core::settings.about_updates.check_on' : 'core::settings.about_updates.check_off') }}
                </p>
            </div>
            <x-core::switch
                id="update-check-toggle"
                :on="$enabled"
                :label="Lang::get('core::settings.about_updates.check_label')"
                wire:click="toggle"
                aria-describedby="update-check-help"
            />
        </div>

        {{-- Only while the check is on: with it off the sentence promising that
             new versions arrive by themselves is no longer true of this
             install, and the line above already says what happens instead. --}}
        @if ($enabled)
            <p class="text-sm text-slate-500 dark:text-slate-400">
                {{ Lang::get('core::settings.about_updates.body') }}
            </p>
        @endif

        {{-- Inside the desktop branch, not beneath both. The page it opens is
             where the installers are, so on a phone it is an in-app route to
             an out-of-store binary — and a sentence that names the store above
             a control that bypasses it is the shape both stores refuse. --}}
        <x-core::secondary-button
            size="sm"
            wire:click="openReleasesPage"
        >{{ Lang::get('core::settings.about_updates.open_releases') }}</x-core::secondary-button>
    @endif
</div>
