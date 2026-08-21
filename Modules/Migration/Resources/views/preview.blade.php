@use('Modules\Core\Public\Support\Lang')
@extends('layouts.app', ['title' => Lang::get('migration::preview.page_title').' · Beatrax'])

@section('content')
    <x-core::page-shell width="5xl">
        @livewire('migration.preview-migration', ['id' => $id])
    </x-core::page-shell>
@endsection
