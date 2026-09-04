@use('Modules\Core\Public\Support\Brand')
@use('Modules\Core\Public\Support\Lang')
@extends('layouts.app', ['title' => Lang::get('counterparties::triage.page_title').Brand::TITLE_SUFFIX])

@section('content')
    <x-core::page-shell width="3xl">
        @livewire('counterparties.triage')
    </x-core::page-shell>
@endsection
