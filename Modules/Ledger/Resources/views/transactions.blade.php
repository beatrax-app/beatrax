@extends('layouts.app', ['title' => 'Transactions · diederik'])

@section('content')
    <main class="min-h-screen bg-white dark:bg-slate-950">
        <div class="mx-auto max-w-5xl px-8 py-12">
            @livewire('ledger.transactions-list')
        </div>
    </main>
@endsection
