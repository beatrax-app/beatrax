@extends('layouts.app', ['title' => 'Upload statement · diederik'])

@section('content')
    <main class="min-h-screen bg-white">
        <div class="mx-auto max-w-3xl px-6 py-16">
            @livewire('import.upload-wizard')
        </div>
    </main>
@endsection
