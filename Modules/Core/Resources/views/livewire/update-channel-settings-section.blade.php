@use('Modules\Core\Public\Enums\UpdateChannel')
@use('Modules\Core\Public\Support\Lang')
{{--
    Which manifest set this installation asks the release feed for. Drawn only
    off a store build: there the stores own the update path entirely, the three
    AutoUpdater listeners return before they read anything, and a channel would
    name a choice between two things neither of which happens here.
--}}
<div>
    @unless ($onPhone)
        <div class="space-y-2">
            <x-core::form-field
                name="channel"
                type="select"
                field-id="update-channel-select"
                :label="Lang::get('core::settings.about_updates.channel_label')"
                :hint="Lang::get('core::settings.about_updates.channel_help')"
                wire:model="channel"
                wire:change="choose"
                class="max-w-xs"
            >
                <option value="{{ UpdateChannel::Stable->value }}">{{ Lang::get('core::settings.about_updates.channel_stable') }}</option>
                <option value="{{ UpdateChannel::Preview->value }}">{{ Lang::get('core::settings.about_updates.channel_preview') }}</option>
            </x-core::form-field>

            {{-- Only while preview is chosen: the warning is about what this
                 install now accepts, and above a stable install it would be a
                 caution against a thing that is not happening. --}}
            @if ($channel === UpdateChannel::Preview->value)
                <p class="max-w-prose text-xs text-amber-700 dark:text-amber-500">
                    {{ Lang::get('core::settings.about_updates.channel_preview_note') }}
                </p>
            @endif
        </div>
    @endunless
</div>
