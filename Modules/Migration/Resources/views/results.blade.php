@extends('layouts.app', ['title' => 'Import complete · beatrax'])

@section('content')
    <main class="min-h-screen bg-white dark:bg-slate-950">
        <div class="mx-auto max-w-3xl px-6 py-16">
            @livewire('migration.migration-results', ['id' => $id])
        </div>
    </main>
@endsection
