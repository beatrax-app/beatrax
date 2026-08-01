@use('Modules\Core\Public\Support\Lang')
@extends('layouts.app', ['title' => Lang::get('categorization::rules.page_title').' · beatrax'])

@section('content')
    <main class="min-h-screen bg-white dark:bg-slate-950">
        <div class="mx-auto max-w-5xl px-8 py-12">
            @livewire(\Modules\Categorization\Internal\Http\Livewire\RulesPage::class)
        </div>
    </main>
@endsection
