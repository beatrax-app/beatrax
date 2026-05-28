@extends('layouts.app', ['title' => 'Counterparty · beatrax'])

@section('content')
    <main class="min-h-screen bg-white dark:bg-slate-950">
        <div class="mx-auto max-w-6xl px-8 py-12">
            @livewire('counterparties.profile', ['slug' => $slug])
        </div>
    </main>
@endsection
