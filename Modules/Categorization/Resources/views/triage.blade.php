@use('Modules\Core\Public\Support\Lang')
@extends('layouts.app', ['title' => Lang::get('categorization::triage.page_title').' · Beatrax'])

@section('content')
    <x-core::page-shell width="5xl">
        @livewire('categorization.triage-inbox')
    </x-core::page-shell>
@endsection
