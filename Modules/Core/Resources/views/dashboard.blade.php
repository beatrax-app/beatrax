@extends('layouts.app', ['title' => 'Dashboard · diederik'])

@section('content')
    <main class="min-h-screen bg-white">
        <div class="mx-auto max-w-5xl px-8 py-12">
            @livewire('core.dashboard')
        </div>
    </main>
@endsection
