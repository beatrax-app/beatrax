@use('Modules\Core\Public\Support\Brand')
@use('Modules\Core\Public\Support\Lang')
@extends('layouts.app', ['title' => Lang::get('categorization::rules.page_title').Brand::TITLE_SUFFIX])

@section('content')
    <x-core::page-shell width="5xl">
        @livewire(\Modules\Categorization\Internal\Http\Livewire\RulesPage::class)
    </x-core::page-shell>
@endsection
