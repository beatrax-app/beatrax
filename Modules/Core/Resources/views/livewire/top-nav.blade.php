@php
    $isActive = static fn (string $path): string => $currentPath === $path
        ? 'text-slate-900 font-semibold bg-slate-100'
        : 'text-slate-500 hover:text-slate-900 hover:bg-slate-100';
@endphp

<nav class="border-b border-slate-200 bg-white" aria-label="Primary">
    <div class="mx-auto flex max-w-5xl items-center gap-1 px-8 py-3">
        <a
            href="{{ route('dashboard') }}"
            class="mr-4 text-sm font-semibold tracking-tight text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
        >diederik</a>

        <a
            href="{{ route('dashboard') }}"
            class="inline-flex items-center rounded-md px-3 py-1.5 text-sm {{ $isActive('/') }} focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
        >Dashboard</a>

        <a
            href="{{ route('transactions.index') }}"
            class="inline-flex items-center rounded-md px-3 py-1.5 text-sm {{ $isActive('/transactions') }} focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
        >Transactions</a>

        <a
            href="{{ route('imports.new') }}"
            class="inline-flex items-center rounded-md px-3 py-1.5 text-sm {{ $isActive('/imports/new') }} focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
        >Imports</a>

        <a
            href="{{ route('uncategorized') }}"
            class="inline-flex items-center gap-2 rounded-md px-3 py-1.5 text-sm {{ $isActive('/uncategorized') }} focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
        >
            Uncategorized
            @if ($uncategorizedCount > 0)
                <span
                    class="inline-flex items-center justify-center rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-900"
                    style="font-variant-numeric: tabular-nums;"
                >{{ $uncategorizedCount }}</span>
            @endif
        </a>

        <a
            href="{{ route('settings') }}"
            class="inline-flex items-center rounded-md px-3 py-1.5 text-sm {{ $isActive('/settings') }} focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
        >Settings</a>

        <span class="flex-1"></span>

        <span class="text-sm text-slate-500">{{ $userEmail }}</span>

        <form method="POST" action="{{ route('logout') }}" class="ml-2">
            @csrf
            <button
                type="submit"
                class="inline-flex items-center rounded-md px-3 py-1.5 text-sm text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
            >Sign out</button>
        </form>
    </div>
</nav>
