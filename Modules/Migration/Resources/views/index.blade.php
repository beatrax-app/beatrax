@extends('layouts.app', ['title' => 'Migrations · beatrax'])

@section('content')
    <main class="min-h-screen bg-white dark:bg-slate-950">
        <div class="mx-auto max-w-3xl px-6 py-16">
            @livewire('migration.migrations-index')
        </div>
    </main>
@endsection
