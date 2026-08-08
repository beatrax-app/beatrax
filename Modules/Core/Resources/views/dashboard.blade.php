@use('Modules\Core\Public\Support\Lang')
@extends('layouts.app', ['title' => Lang::get('core::dashboard.page_title').' · Beatrax'])

@section('content')
    <main class="min-h-screen bg-white dark:bg-slate-950">
        <div class="mx-auto max-w-5xl px-8 py-12">
            @livewire('core.dashboard')
        </div>
    </main>
@endsection
