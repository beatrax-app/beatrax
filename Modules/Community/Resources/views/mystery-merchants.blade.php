@use('Modules\Core\Public\Support\Lang')
@extends('layouts.app', ['title' => Lang::get('community::mystery.page_title').' · Beatrax'])

@section('content')
        <div class="mx-auto max-w-5xl px-8 py-12">
            @livewire('community.mystery-merchants-page')
        </div>
@endsection
