@use('Modules\Core\Public\Support\Brand')
@use('Modules\Core\Public\Support\Lang')
@extends('layouts.app', ['title' => Lang::get('import::results.page_title').Brand::TITLE_SUFFIX])

@section('content')
    <x-core::page-shell width="3xl">
        @livewire('import.import-results', ['id' => $id])
    </x-core::page-shell>
@endsection
