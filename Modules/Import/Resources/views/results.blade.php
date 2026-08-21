@use('Modules\Core\Public\Support\Lang')
@extends('layouts.app', ['title' => Lang::get('import::results.page_title').' · Beatrax'])

@section('content')
    <x-core::page-shell width="3xl">
        @livewire('import.import-results', ['id' => $id])
    </x-core::page-shell>
@endsection
