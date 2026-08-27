@use('Modules\Core\Public\Support\Lang')
@extends('layouts.app', ['title' => Lang::get('import::upload.page_title').' · Beatrax'])

@section('content')
    <x-core::page-shell width="3xl">
        @livewire('import.upload-wizard')

        {{-- Coming from another budgeting app is a once-ever errand, so it
             does not belong in the sidebar on every screen. It belongs
             here: this is the page you are already on when you are trying
             to get existing data in. Secondary styling, because the
             statement upload above is what most people came for. --}}
        <p class="mt-10 border-t border-slate-100 pt-6 text-sm text-slate-500 dark:border-slate-800 dark:text-slate-400">
            {{ Lang::get('import::upload.migrate_prompt') }}
            <a
                href="{{ route('migrations.index') }}"
                wire:navigate
                class="tap-link font-medium text-slate-900 underline underline-offset-2 hover:no-underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-100 dark:focus-visible:ring-slate-100"
            >{{ Lang::get('import::upload.migrate_link') }}</a>
        </p>
    </x-core::page-shell>
@endsection
