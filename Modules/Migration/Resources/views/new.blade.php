@use('Modules\Core\Public\Support\Brand')
@use('Modules\Core\Public\Support\Lang')
@extends('layouts.app', ['title' => Lang::get('migration::new.page_title').Brand::TITLE_SUFFIX])

@section('content')
    <x-core::page-shell width="3xl">
        @livewire('migration.new-migration')
    </x-core::page-shell>
@endsection
