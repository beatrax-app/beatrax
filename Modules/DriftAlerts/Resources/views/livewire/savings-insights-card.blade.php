@use('Modules\Core\Public\Navigation\Destination')
@use('Modules\Core\Public\Support\Lang')
<div>
    @if (count($insights) > 0)
        <x-core::card tag="section" aria-label="{{ Lang::get('drift-alerts::savings.aria') }}">
            <div class="flex flex-wrap items-baseline justify-between gap-4">
                <x-core::section-heading :title="Lang::get('drift-alerts::savings.heading')" />
                <a href="{{ Destination::Subscriptions->url() }}" class="tap-link text-xs font-medium text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100">{{ Lang::get('drift-alerts::savings.subscriptions_link') }}</a>
            </div>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                {{ Lang::get('drift-alerts::savings.disclaimer') }}
            </p>

            <ul class="mt-4 space-y-2">
                @foreach ($insights as $insight)
                    <li
                        wire:key="insight-{{ $insight->key }}"
                        class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-md border border-slate-100 px-3 py-2 dark:border-slate-800"
                    >
                        {{-- The action label is a translation: beside a
                             zero-basis sentence, "Δες φθηνότερα προγράμματα"
                             left the Greek message 6px of the row and painted
                             the rest under the button. The basis is the width
                             below which the sentence takes the row and the
                             actions drop beneath it.

                             The group shrinks rather than holding max-content,
                             because the longest of the 26 labels — "Goedkopere
                             abonnementen bekijken" — is 319px beside a 36px
                             dismiss, wider than the row it wraps onto at 390.
                             Shrinking wraps that label; .emoji-action is
                             flex:none, so the dismiss keeps its own reach. --}}
                        <p class="min-w-0 flex-1 basis-64 text-sm text-slate-700 dark:text-slate-300">{{ $insight->message }}</p>
                        <div class="ml-auto flex min-w-0 items-center gap-1">
                            {{-- No scheme test here. The action URL is the
                                 corpus cancel_url or cheaper_url, and the one
                                 external-URL gate judged it before the DTO was
                                 built; a refused one raises no insight at all.
                                 A second test here was how `http://` got in:
                                 this template accepted what the reader that
                                 supplies the value already refused. --}}
                            <x-core::secondary-button
                                :href="$insight->actionUrl"
                                size="sm"
                                class="gap-1 text-center"
                                target="_blank"
                                rel="noopener noreferrer"
                            >{{ $insight->actionLabel }} <span aria-hidden="true" style="opacity:.6;">↗</span></x-core::secondary-button>
                            <x-core::emoji-action
                                :label="Lang::get('drift-alerts::savings.dismiss_aria')"
                                :caption="Lang::get('drift-alerts::savings.dismiss_caption')"
                                wire:click="dismiss('{{ $insight->key }}')"
                            >✖️</x-core::emoji-action>
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-core::card>
    @endif
</div>
