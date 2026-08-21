@use('Modules\Core\Public\Support\Lang')
@extends('layouts.app', ['title' => Lang::get('community::mystery.page_title').' · Beatrax'])

@section('content')
    <x-core::page-shell width="5xl">
        @livewire('community.mystery-merchants-page')
    </x-core::page-shell>
@endsection
