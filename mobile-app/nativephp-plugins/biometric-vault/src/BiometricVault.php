<?php

declare(strict_types=1);

namespace Beatrax\BiometricVault;

/**
 * SPIKE bridge to the biometric-gated native vault.
 *
 * Mirrors nativephp/mobile-secure-storage's PHP shape (a thin wrapper over
 * `nativephp_call`), but the entry is enclave-bound: `get()` only returns the
 * value after a fresh Face ID / Touch ID.
 *
 * Platform asymmetry (see the plugin's .kt docblock): iOS resolves get()
 * SYNCHRONOUSLY (the keychain blocks on the biometric sheet), so `get()`
 * returns the value inline. Android is ASYNCHRONOUS (BiometricPrompt), so
 * get() returns `['async' => true]` and the value arrives via the
 * `BiometricVault.Recovered` event — the S2 recover flow must handle both.
 */
class BiometricVault
{
    /**
     * Store a value in the biometric-gated entry. Passing null deletes it.
     */
    public function set(string $key, ?string $value): bool
    {
        return $this->callSuccess('BiometricVault.Set', ['key' => $key, 'value' => $value]);
    }

    /**
     * Attempt to read the value, presenting the biometric prompt.
     *
     * @return array{value: ?string, authenticated: bool, async: bool, canceled: bool, failed: bool, missing: bool}
     */
    public function get(string $key, string $reason = 'Unlock beatrax'): array
    {
        $decoded = $this->call('BiometricVault.Get', ['key' => $key, 'reason' => $reason]);

        // An empty result means the native bridge threw (auth error other than
        // cancel, device locked, transient) — surface it as `failed`, NOT as a
        // false `missing`. The distinction is security-relevant: `missing`
        // means "nothing enrolled", `failed` means "enrolled but auth failed".
        if ($decoded === []) {
            return $this->outcome(failed: true);
        }

        $value = $decoded['value'] ?? null;

        return $this->outcome(
            value: is_string($value) && $value !== '' ? $value : null,
            authenticated: ($decoded['authenticated'] ?? false) === true,
            async: ($decoded['async'] ?? false) === true,
            canceled: ($decoded['canceled'] ?? false) === true,
            failed: ($decoded['failed'] ?? false) === true,
            missing: ($decoded['missing'] ?? false) === true,
        );
    }

    /**
     * @return array{value: ?string, authenticated: bool, async: bool, canceled: bool, failed: bool, missing: bool}
     */
    private function outcome(
        ?string $value = null,
        bool $authenticated = false,
        bool $async = false,
        bool $canceled = false,
        bool $failed = false,
        bool $missing = false,
    ): array {
        return compact('value', 'authenticated', 'async', 'canceled', 'failed', 'missing');
    }

    public function delete(string $key): bool
    {
        return $this->callSuccess('BiometricVault.Delete', ['key' => $key]);
    }

    /**
     * Read + consume the transient blob the async (Android) BiometricPrompt
     * callback stashed after a successful decrypt. Returns null when nothing is
     * pending. NO biometric prompt happens here — the key never crosses the JS
     * bridge in the prompt result; PHP collects it after the fact. iOS unused.
     *
     * REPLAY SAFETY (load-bearing native contract): the native PollRecovered
     * MUST be single-shot — DELETE the transient slot on read — and MUST clear
     * it on app background / re-lock, so a stashed blob cannot be replayed by a
     * later spoofed `cold-start-recovered` dispatch to admit without a fresh
     * biometric. The PHP-side gates (isColdStartEnrolled + PIN floor) defend in
     * depth, but enclave freshness depends on this consume-on-read.
     */
    public function pollRecovered(): ?string
    {
        $value = $this->call('BiometricVault.PollRecovered', [])['value'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(string $function, array $payload): array
    {
        if (! function_exists('nativephp_call')) {
            return [];
        }

        $result = nativephp_call($function, (string) json_encode($payload));
        if (! is_string($result) || $result === '') {
            return [];
        }

        $decoded = json_decode($result, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function callSuccess(string $function, array $payload): bool
    {
        return ($this->call($function, $payload)['success'] ?? false) === true;
    }
}
