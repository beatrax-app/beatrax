@extends('layouts.app', ['title' => 'Counterparty triage · beatrax'])

@section('content')
    <main class="min-h-screen bg-white dark:bg-slate-950">
        <div class="mx-auto max-w-3xl px-8 py-12">
            @livewire('counterparties.triage')
        </div>
    </main>
@endsection
