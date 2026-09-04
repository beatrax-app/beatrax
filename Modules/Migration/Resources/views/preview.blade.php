@use('Modules\Core\Public\Support\Brand')
@use('Modules\Core\Public\Support\Lang')
@extends('layouts.app', ['title' => Lang::get('migration::preview.page_title').Brand::TITLE_SUFFIX])

@section('content')
    <x-core::page-shell width="5xl">
        @livewire('migration.preview-migration', ['id' => $id])
    </x-core::page-shell>
@endsection
