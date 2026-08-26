<?php

declare(strict_types=1);

namespace Beatrax\BiometricVault;

/**
 * @link ../../../../.docs/features/mobile/architecture.md#the-vault-bridges-own-contract
 */
class BiometricVault
{
    private ?string $lastError = null;

    // A null value deletes the entry rather than storing an empty one, which is
    // the native side's own convention for this call.
    public function set(string $key, ?string $value): bool
    {
        return $this->callSuccess('BiometricVault.Set', ['key' => $key, 'value' => $value]);
    }

    /**
     * @return array{value: ?string, authenticated: bool, async: bool, canceled: bool, failed: bool, missing: bool}
     */
    public function get(string $key, string $reason = 'Unlock Beatrax'): array
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

    // Consume-on-read is the native side's contract as much as this method's:
    // a transient slot that survives the read, or one that outlives a
    // backgrounding, can be replayed by a later spoofed dispatch and admit a
    // session with no fresh biometric behind it. Android only; iOS never calls.
    public function pollRecovered(): ?string
    {
        $value = $this->call('BiometricVault.PollRecovered', [])['value'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    // The reason the last bridge call refused, or null when it did not. The
    // native side answers a refusal with {status: error, message}; every caller
    // here reduces that to false or [], so without this the only account of a
    // failed enrolment is the word "false".
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(string $function, array $payload): array
    {
        $this->lastError = null;

        $result = $this->bridge($function, (string) json_encode($payload));
        if ($result === null || $result === '') {
            $this->lastError = 'the native bridge did not answer';

            return [];
        }

        $decoded = json_decode($result, true);
        if (! is_array($decoded)) {
            $this->lastError = 'the native bridge answered with something that is not an object';

            return [];
        }

        if (($decoded['status'] ?? null) === 'error') {
            $message = $decoded['message'] ?? null;
            $this->lastError = is_string($message) && $message !== '' ? $message : 'the native bridge reported an error';

            return [];
        }

        return $decoded;
    }

    // Native seam, confined to this one method so a test can answer for the
    // bridge without defining a global function the whole suite would inherit.
    protected function bridge(string $function, string $payload): ?string
    {
        if (! function_exists('nativephp_call')) {
            return null;
        }

        $result = nativephp_call($function, $payload);

        return is_string($result) ? $result : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function callSuccess(string $function, array $payload): bool
    {
        return ($this->call($function, $payload)['success'] ?? false) === true;
    }
}
