@use('Modules\Core\Public\Support\Lang')
@extends('layouts.app', ['title' => Lang::get('import::preview.page_title').' · Beatrax'])

@section('content')
    <x-core::page-shell width="5xl">
        @livewire('import.preview-wizard', ['id' => $id])
    </x-core::page-shell>
@endsection
