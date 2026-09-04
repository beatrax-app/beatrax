@use('Modules\Core\Public\Support\Brand')
@use('Modules\Core\Public\Support\Lang')
@extends('layouts.app', ['title' => Lang::get('counterparties::index.page_title').Brand::TITLE_SUFFIX])

@section('content')
    <x-core::page-shell width="6xl">
        @livewire('counterparties.index')
    </x-core::page-shell>
@endsection
