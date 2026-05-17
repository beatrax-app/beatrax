<!doctype html>
<html lang="en" class="bg-white text-slate-900" style="font-feature-settings: 'tnum';">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <meta name="csrf-token" content="{{ csrf_token() }}" />
        <title>{{ $title ?? 'diederik' }}</title>
        @vite(['resources/css/app.css'])
        @livewireStyles
    </head>
    <body class="antialiased bg-white text-slate-900" style="font-family: 'Inter', system-ui, -apple-system, sans-serif;">
        @auth
            @livewire('core.top-nav')
            @livewire('categorization.rule-form-modal')
            @livewire('categorization.correction-divergence-toast')
            @livewire('receipts.receipt-conflict-toast')
        @endauth
        @yield('content')
        @livewireScripts
    </body>
</html>
