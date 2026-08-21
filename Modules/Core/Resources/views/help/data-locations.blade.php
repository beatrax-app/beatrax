@use('Modules\Core\Public\Support\Lang')
@extends('layouts.app', ['title' => Lang::get('core::help.page_title').' · Beatrax'])

@section('content')
    <x-core::page-shell width="3xl">
        @livewire('core.help-data-locations')
    </x-core::page-shell>
@endsection
