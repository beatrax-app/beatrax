@use('Modules\Core\Public\Support\Lang')
@extends('layouts.app', ['title' => Lang::get('core::settings.title').' · Beatrax'])

@section('content')
    <x-core::page-shell width="5xl">
        @livewire('core.settings-page')
    </x-core::page-shell>
@endsection
