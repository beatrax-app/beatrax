@extends('layouts.app', ['title' => 'Sign in · diederik'])

@section('content')
    <main class="min-h-screen flex items-center justify-center bg-white">
        <div class="w-full max-w-sm px-6">
            @include('core::auth.login-form')
        </div>
    </main>
@endsection
