@use('Modules\Core\Public\Support\Lang')
@extends('layouts.app', ['title' => Lang::get('core::help.page_title').' · beatrax'])

@section('content')
    <main class="min-h-screen bg-white dark:bg-slate-950">
        <div class="mx-auto max-w-3xl px-8 py-12">
            @livewire('core.help-data-locations')
        </div>
    </main>
@endsection
