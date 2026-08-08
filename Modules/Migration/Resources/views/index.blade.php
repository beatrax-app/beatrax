@use('Modules\Core\Public\Support\Lang')
@extends('layouts.app', ['title' => Lang::get('migration::index.page_title').' · Beatrax'])

@section('content')
    <main class="min-h-screen bg-white dark:bg-slate-950">
        <div class="mx-auto max-w-3xl px-6 py-16">
            @livewire('migration.migrations-index')
        </div>
    </main>
@endsection
