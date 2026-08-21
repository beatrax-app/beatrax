@use('Modules\Core\Public\Support\Lang')
@extends('layouts.app', ['title' => Lang::get('counterparties::profile.page_title').' · Beatrax'])

@section('content')
    <x-core::page-shell width="6xl">
        @livewire('counterparties.profile', ['slug' => $slug])
    </x-core::page-shell>
@endsection
