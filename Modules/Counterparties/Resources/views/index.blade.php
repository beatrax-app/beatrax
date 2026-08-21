@use('Modules\Core\Public\Support\Lang')
@extends('layouts.app', ['title' => Lang::get('counterparties::index.page_title').' · Beatrax'])

@section('content')
    <x-core::page-shell width="6xl">
        @livewire('counterparties.index')
    </x-core::page-shell>
@endsection
