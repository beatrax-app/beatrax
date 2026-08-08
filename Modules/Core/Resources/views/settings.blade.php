@use('Modules\Core\Public\Support\Lang')
@extends('layouts.app', ['title' => Lang::get('core::settings.title').' · Beatrax'])

@section('content')
    <main class="min-h-screen bg-white dark:bg-slate-950">
        <div class="mx-auto max-w-5xl px-8 py-12">
            @livewire('core.settings-page')
        </div>
    </main>
@endsection
