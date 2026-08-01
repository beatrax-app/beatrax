@use('Modules\Core\Public\Support\Lang')
{{--
    LOCK-04 dev-console probe (D-19).

    Displays the current release/withhold state of the data-key gate.
    Mounted on the Developer Console overview page ONLY — behind the
    `developer` middleware (T-05-27).

    D-22 note: the lock gates the per-session UI release only.
    Long-lived background workers retain the data key independently of
    the session lock state.

    Security: NEVER output raw key bytes — only the 8-char SHA-256
    fingerprint prefix is rendered (T-05-26).
--}}
<div
    class="rounded-md p-3 space-y-2"
    style="background:#0b1220; color:#f1f5f9; font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 0.75rem;"
    data-testid="app-lock-key-probe"
    wire:poll.5s
>
    <div class="flex items-center justify-between gap-2">
        <span class="text-[#94a3b8] uppercase tracking-wide text-[10px]">{{ Lang::get('auth::key_probe.label') }}</span>
        @if ($status === 'released')
            <span
                class="text-[#6ee7b7] font-semibold text-[11px] uppercase tracking-wide"
                data-testid="probe-status-released"
            >{{ Lang::get('auth::key_probe.released') }}</span>
        @else
            <span
                class="text-[#fca5a5] font-semibold text-[11px] uppercase tracking-wide"
                data-testid="probe-status-withheld"
            >{{ Lang::get('auth::key_probe.withheld') }}</span>
        @endif
    </div>

    @if ($status === 'released' && $fingerprint !== null)
        {{-- Fingerprint: 8-char SHA-256 prefix. Proves key presence without disclosure. --}}
        <div class="text-[#94a3b8] text-[10px]">
            {{ Lang::get('auth::key_probe.fingerprint') }}
            <span
                class="text-[#f1f5f9]"
                style="font-variant-numeric: tabular-nums;"
                data-testid="probe-fingerprint"
            >{{ $fingerprint }}…</span>
        </div>
    @endif

    {{-- D-22 note for developers reading this panel --}}
    <div class="text-[#475569] text-[10px] leading-snug pt-1">
        {{ Lang::get('auth::key_probe.note') }}
    </div>

    <div class="flex items-center gap-2 pt-1">
        @if ($status === 'released')
            <button
                wire:click="lock"
                class="px-2 py-0.5 rounded text-[10px] text-[#fca5a5] border border-[#7f1d1d] hover:bg-[#7f1d1d]/20 transition"
                data-testid="probe-lock-btn"
            >{{ Lang::get('auth::key_probe.lock_now') }}</button>
        @endif
        <button
            wire:click="refresh"
            class="px-2 py-0.5 rounded text-[10px] text-[#94a3b8] border border-[#1e293b] hover:bg-[#1e293b]/40 transition"
            data-testid="probe-refresh-btn"
        >{{ Lang::get('auth::key_probe.refresh') }}</button>
    </div>
</div>
