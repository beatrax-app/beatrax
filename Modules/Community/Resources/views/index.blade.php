@use('Modules\Core\Public\Support\Lang')
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
        <div class="mx-auto max-w-3xl space-y-10 px-6 py-10 sm:px-8 sm:py-12">
            <header class="space-y-2">
                <h1 class="text-3xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">
                    {{ Lang::get('community::index.heading') }}
                </h1>
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
                <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                    <p class="max-w-prose text-sm text-slate-500 dark:text-slate-400">
                        {{ Lang::get('community::index.contribute_body') }}
                    </p>
                    <a
                        href="{{ route('community.mystery-merchants') }}"
                        wire:navigate
                        class="mt-4 inline-flex min-h-[44px] items-center rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
                    >
                        {{ Lang::get('community::index.contribute_cta') }}
                    </a>
                </div>
            </section>

            {{-- Translations are the other thing the community owns: the app
                 now carries a language switcher built to grow, and this is
                 where a person finds out how to make it grow. --}}
            <section class="space-y-3" id="community-translations" aria-labelledby="community-translations-heading">
                <h2 id="community-translations-heading" class="text-lg font-semibold tracking-tight text-slate-900 dark:text-slate-100">
                    {{ Lang::get('community::index.translations_heading') }}
                </h2>
                <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                    <p class="max-w-prose text-sm text-slate-500 dark:text-slate-400">
                        {{ Lang::get('community::index.translations_body') }}
                    </p>
                    {{-- Said plainly rather than discovered: a translation that
                         reads slightly off is easier to forgive, and far easier
                         to fix, once you know where it came from. --}}
                    <p class="mt-3 max-w-prose rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-200">
                        {{ Lang::get('community::index.translations_ai_notice') }}
                    </p>
                    <p class="mt-3 max-w-prose text-sm text-slate-500 dark:text-slate-400">
                        {{ Lang::get('community::index.translations_how') }}
                    </p>
                    <a
                        href="{{ Lang::get('community::index.translations_url') }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="mt-4 inline-flex min-h-[44px] items-center rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-900 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:hover:bg-slate-800"
                    >
                        {{ Lang::get('community::index.translations_cta') }} &rarr;
                    </a>
                </div>
            </section>

            <section class="space-y-3" id="shared-merchant-list" aria-labelledby="community-shared-heading">
                <h2 id="community-shared-heading" class="text-lg font-semibold tracking-tight text-slate-900 dark:text-slate-100">
                    {{ Lang::get('community::index.shared_heading') }}
                </h2>
                <div class="rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-950">
                    @livewire('community.shared-list-settings-panel')
                </div>
            </section>
        </div>
@endsection
