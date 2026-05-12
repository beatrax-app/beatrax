<!doctype html>
<html lang="en" class="bg-white text-slate-900" style="font-feature-settings: 'tnum';">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <meta name="csrf-token" content="{{ csrf_token() }}" />
        <title>{{ $title ?? 'diederik' }}</title>
        <link rel="preconnect" href="https://fonts.bunny.net" />
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600&display=swap" rel="stylesheet" />
        @vite(['resources/css/app.css'])
        @livewireStyles
    </head>
    <body class="antialiased bg-white text-slate-900" style="font-family: 'Inter', system-ui, -apple-system, sans-serif;">
        @yield('content')
        @livewireScripts
    </body>
</html>
