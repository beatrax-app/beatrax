@extends('layouts.app', ['title' => 'Import complete · diederik'])

@section('content')
    <main class="min-h-screen bg-white">
        <div class="mx-auto max-w-3xl px-6 py-16">
            @livewire('import.import-results', ['id' => $id])
        </div>
    </main>
@endsection
