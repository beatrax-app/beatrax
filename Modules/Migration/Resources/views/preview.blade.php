@extends('layouts.app', ['title' => 'Preview import · beatrax'])

@section('content')
    <main class="min-h-screen bg-white dark:bg-slate-950">
        <div class="mx-auto max-w-5xl px-6 py-16">
            @livewire('migration.preview-migration', ['id' => $id])
        </div>
    </main>
@endsection
