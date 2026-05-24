@extends('layouts.app', ['title' => 'Upload statement · beatrax'])

@section('content')
    <main class="min-h-screen bg-white dark:bg-slate-950">
        <div class="mx-auto max-w-3xl px-6 py-16">
            @livewire('import.upload-wizard')
        </div>
    </main>
@endsection
