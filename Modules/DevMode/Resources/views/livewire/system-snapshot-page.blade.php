<div class="p-6 space-y-6" data-testid="system-snapshot-page">
    {{--
        Snapshot sections share a fixed-width key column so the
        divider between key and value stays in the same X position
        across every card. Without this each section's <table>
        sized its key column to the longest key in that section
        alone, which made every section's value column start at a
        different X — visually misaligned cards stacked vertically.

        The fixed-width is applied per row via Tailwind's `w-40`
        on each <th>; the table layout collapses excess width onto
        the value column.
    --}}
    @php
        $keyTh = 'text-left text-xs uppercase text-[var(--color-text-muted)] pr-4 w-40 align-top';
    @endphp
    <header class="space-y-1">
        <h1 class="text-xl font-semibold text-[var(--color-text)]">System snapshot</h1>
        <p class="text-sm text-[var(--color-text-muted)]">
            Environment + runtime + effective configuration. Sensitive keys (suffixes
            <code class="font-mono text-xs">*password*</code>,
            <code class="font-mono text-xs">*secret*</code>,
            <code class="font-mono text-xs">*key</code>,
            <code class="font-mono text-xs">*token*</code>)
            are masked.
        </p>
    </header>

    {{-- PHP --}}
    <section class="card p-4" data-testid="snapshot-section-php">
        <h2 class="text-sm font-semibold mb-3">PHP</h2>
        <table class="table w-full text-sm" style="font-variant-numeric: tabular-nums;">
            <tbody>
                <tr><th class="{{ $keyTh }}">version</th><td class="font-mono">{{ $php['version'] }}</td></tr>
                <tr><th class="{{ $keyTh }}">sapi</th><td class="font-mono">{{ $php['sapi'] }}</td></tr>
                <tr><th class="{{ $keyTh }}">ini path</th><td class="font-mono text-xs">{{ $php['ini_path'] }}</td></tr>
                <tr>
                    <th class="{{ $keyTh }}">extensions</th>
                    <td class="text-xs">{{ implode(', ', $php['extensions']) }}</td>
                </tr>
            </tbody>
        </table>
    </section>

    {{-- Laravel --}}
    <section class="card p-4" data-testid="snapshot-section-laravel">
        <h2 class="text-sm font-semibold mb-3">Laravel</h2>
        <table class="table w-full text-sm">
            <tbody>
                @foreach ($laravel as $k => $v)
                    <tr>
                        <th class="{{ $keyTh }}">{{ $k }}</th>
                        <td class="font-mono">{{ is_bool($v) ? ($v ? 'true' : 'false') : (is_scalar($v) ? $v : json_encode($v)) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    {{-- SQLite --}}
    <section class="card p-4" data-testid="snapshot-section-sqlite">
        <h2 class="text-sm font-semibold mb-3">SQLite</h2>
        <table class="table w-full text-sm">
            <tbody>
                @foreach ($sqlite['pragmas'] as $k => $v)
                    <tr>
                        <th class="{{ $keyTh }}">{{ $k }}</th>
                        <td class="font-mono">{{ $v }}</td>
                    </tr>
                @endforeach
                <tr><th class="{{ $keyTh }}">file</th><td class="font-mono text-xs">{{ $sqlite['file'] }}</td></tr>
                <tr><th class="{{ $keyTh }}">file size</th><td class="font-mono">{{ $sqlite['file_size'] ?? '(missing)' }}</td></tr>
            </tbody>
        </table>
    </section>

    {{-- Paths --}}
    <section class="card p-4" data-testid="snapshot-section-paths">
        <h2 class="text-sm font-semibold mb-3">Paths</h2>
        <table class="table w-full text-xs">
            <tbody>
                @foreach ($paths as $k => $v)
                    <tr>
                        <th class="{{ $keyTh }}">{{ $k }}</th>
                        <td class="font-mono">{{ $v }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    {{-- Environment --}}
    <section class="card p-4" data-testid="snapshot-section-env">
        <h2 class="text-sm font-semibold mb-3">Environment</h2>
        @if (count($env) === 0)
            <p class="text-sm text-[var(--color-text-muted)]">(no BEATRAX_*, NATIVEPHP_*, or APP_KEY env vars set)</p>
        @else
            <table class="table w-full text-xs">
                <tbody>
                    @foreach ($env as $k => $v)
                        <tr>
                            <th class="{{ $keyTh }} font-mono">{{ $k }}</th>
                            <td class="font-mono">{{ $v }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    {{-- Runtime --}}
    <section class="card p-4" data-testid="snapshot-section-runtime">
        <h2 class="text-sm font-semibold mb-3">Runtime</h2>
        <table class="table w-full text-xs">
            <tbody>
                <tr><th class="{{ $keyTh }}">nativephp</th><td class="font-mono">{{ $runtime['nativephp'] }}</td></tr>
                <tr><th class="{{ $keyTh }}">host os</th><td class="font-mono">{{ $runtime['host_os'] }}</td></tr>
            </tbody>
        </table>
    </section>

    {{-- Effective config (collapsed by default) --}}
    <section class="card p-4" data-testid="snapshot-section-config" x-data="{ open: false }">
        <h2 class="text-sm font-semibold mb-3 flex items-center justify-between">
            Effective configuration
            <button type="button" class="text-xs text-[var(--color-text-muted)] underline" x-on:click="open = !open">
                <span x-show="!open">Show {{ count($effectiveConfig) }} entries</span>
                <span x-show="open" x-cloak>Hide</span>
            </button>
        </h2>
        <div x-show="open" x-cloak class="max-h-96 overflow-auto">
            <table class="table w-full text-xs">
                <tbody>
                    @foreach ($effectiveConfig as $k => $v)
                        <tr>
                            <th class="text-left font-mono text-[var(--color-text-muted)] pr-4 align-top">{{ $k }}</th>
                            <td class="font-mono break-all">{{ is_bool($v) ? ($v ? 'true' : 'false') : (is_scalar($v) ? $v : (is_null($v) ? 'null' : json_encode($v))) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</div>
