@extends('layouts.app', ['title' => 'Mystery merchants · beatrax'])

@section('content')
    <main class="min-h-screen bg-white dark:bg-slate-950">
        <div class="mx-auto max-w-5xl px-8 py-12">
            @livewire('community.mystery-merchants-page')
            @livewire('community.suggest-mapping-modal')
        </div>
    </main>
@endsection
