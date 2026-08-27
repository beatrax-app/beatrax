@use('Modules\Core\Public\Support\Lang')
@use('Modules\Core\Public\Support\ProjectLinks')
@extends('layouts.app', ['title' => Lang::get('community::index.page_title').' · Beatrax'])

{{--
    The Community hub.

    Shared configuration used to sit as one card among twenty on Settings,
    which framed it as a preference. It is not: the shared list is something
    the user both consumes and contributes to, and the mystery-merchant queue
    only makes sense next to it. Giving it a page of its own is what lets the
    two read as one activity — and leaves room for what comes next.
--}}
@section('content')
        <div class="mx-auto max-w-3xl space-y-10 px-4 py-12 sm:px-8">
            <header class="space-y-2">
                <x-core::page-heading level="section">
                    {{ Lang::get('community::index.heading') }}
                </x-core::page-heading>
                <p class="max-w-prose text-sm text-slate-500 dark:text-slate-400">
                    {{ Lang::get('community::index.subtitle') }}
                </p>
            </header>

            {{-- Contribute first: the queue is the thing a person can act on
                 right now, and it is what makes the list below better. --}}
            <section class="space-y-3" id="community-contribute" aria-labelledby="community-contribute-heading">
                <h2 id="community-contribute-heading" class="text-lg font-semibold tracking-tight text-slate-900 dark:text-slate-100">
                    {{ Lang::get('community::index.contribute_heading') }}
                </h2>
                <x-core::card>
                    <p class="max-w-prose text-sm text-slate-500 dark:text-slate-400">
                        {{ Lang::get('community::index.contribute_body') }}
                    </p>
                    <x-core::neutral-button
                        :href="route('community.mystery-merchants')"
                        class="mt-4 min-h-[44px]"
                        wire:navigate
                    >
                        {{ Lang::get('community::index.contribute_cta') }}
                    </x-core::neutral-button>
                </x-core::card>
            </section>

            {{-- Translations are the other thing the community owns: the app
                 now carries a language switcher built to grow, and this is
                 where a person finds out how to make it grow. --}}
            <section class="space-y-3" id="community-translations" aria-labelledby="community-translations-heading">
                <h2 id="community-translations-heading" class="text-lg font-semibold tracking-tight text-slate-900 dark:text-slate-100">
                    {{ Lang::get('community::index.translations_heading') }}
                </h2>
                <x-core::card>
                    <p class="max-w-prose text-sm text-slate-500 dark:text-slate-400">
                        {{ Lang::get('community::index.translations_body') }}
                    </p>
                    {{-- Said plainly rather than discovered: a translation that
                         reads slightly off is easier to forgive, and far easier
                         to fix, once you know where it came from. --}}
                    <x-core::alert tone="warning" class="mt-3 max-w-prose">
                        {{ Lang::get('community::index.translations_ai_notice') }}
                    </x-core::alert>
                    <p class="mt-3 max-w-prose text-sm text-slate-500 dark:text-slate-400">
                        {{ Lang::get('community::index.translations_how') }}
                    </p>
                    <x-core::secondary-button
                        :href="ProjectLinks::CONTRIBUTING_URL"
                        class="mt-4 min-h-[44px]"
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        {{ Lang::get('community::index.translations_cta') }} &rarr;
                    </x-core::secondary-button>
                </x-core::card>
            </section>

            <section class="space-y-3" id="shared-merchant-list" aria-labelledby="community-shared-heading">
                <h2 id="community-shared-heading" class="text-lg font-semibold tracking-tight text-slate-900 dark:text-slate-100">
                    {{ Lang::get('community::index.shared_heading') }}
                </h2>
                <x-core::card>
                    @livewire('community.shared-list-settings-panel')
                </x-core::card>
            </section>
        </div>
@endsection
