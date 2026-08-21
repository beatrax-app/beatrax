@use('Modules\Core\Public\Support\Lang')
@extends('layouts.app', ['title' => Lang::get('migration::new.page_title').' · Beatrax'])

@section('content')
    <x-core::page-shell width="3xl">
        @livewire('migration.new-migration')
    </x-core::page-shell>
@endsection
