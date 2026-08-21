@use('Modules\Core\Public\Support\Lang')
@extends('layouts.app', ['title' => Lang::get('categorization::rules.page_title').' · Beatrax'])

@section('content')
    <x-core::page-shell width="5xl">
        @livewire(\Modules\Categorization\Internal\Http\Livewire\RulesPage::class)
    </x-core::page-shell>
@endsection
