@use('Modules\Core\Public\Support\Lang')
@extends('layouts.app', ['title' => Lang::get('migration::index.page_title').' · Beatrax'])

@section('content')
    <x-core::page-shell width="3xl">
        @livewire('migration.migrations-index')
    </x-core::page-shell>
@endsection
