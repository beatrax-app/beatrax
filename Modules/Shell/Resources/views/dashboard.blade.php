@use('Modules\Core\Public\Support\Brand')
@use('Modules\Core\Public\Support\Lang')
@extends('layouts.app', ['title' => Lang::get('core::dashboard.page_title').Brand::TITLE_SUFFIX])

@section('content')
    <x-core::page-shell width="5xl">
        @livewire('core.dashboard')
    </x-core::page-shell>
@endsection
