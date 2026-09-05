<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport;

// What a catch-up answer did NOT send, and who could vouch for the devices that
// signed it. Sent because the alternative is the failure this filter was nearly
// shipped with: a receiver quietly holding less history than the household has,
// with nothing on any screen saying which device's writes are missing.
/**
 * @link ../../../../.docs/features/sync/introducing-a-device-nobody-can-pair-with.md
 */
final readonly class WithheldHistory
{
    /**
     * @param  array<array-key, int>  $counts  author device id => entries withheld.
     *                                         Keyed by a wire-supplied id, which PHP turns into
     *                                         an int when it reads as a decimal integer.
     * @param  list<array{device_id: string, name: string, ed25519_public_key_hex: string}>  $introductions
     */
    private function __construct(public array $counts, public array $introductions) {}

    /**
     * @param  array<array-key, int>  $counts
     * @param  list<array{device_id: string, name: string, ed25519_public_key_hex: string}>  $introductions
     */
    public static function of(array $counts, array $introductions): self
    {
        return new self($counts, $introductions);
    }

    public static function none(): self
    {
        return new self([], []);
    }

    public function total(): int
    {
        return array_sum($this->counts);
    }

    /**
     * @return list<string>
     */
    public function authors(): array
    {
        return array_map(strval(...), array_keys($this->counts));
    }

    /**
     * @return array{withheld: list<array{device_id: string, count: int}>, introductions: list<array{device_id: string, name: string, ed25519_public_key_hex: string}>}
     */
    public function toWire(): array
    {
        $withheld = [];

        foreach ($this->counts as $deviceId => $count) {
            // Cast because the key may be an int: PHP silently narrows a
            // decimal-string array key, and this one arrives on the wire. The
            // declared array-key type is what says the cast is not redundant.
            $withheld[] = ['device_id' => (string) $deviceId, 'count' => $count];
        }

        return ['withheld' => $withheld, 'introductions' => $this->introductions];
    }

    // Read off a peer's answer, which is attacker-shaped input even inside an
    // authenticated session: every field is checked, a malformed entry is
    // dropped rather than repaired, and a key that is not 64 lowercase hex
    // characters is refused here so no caller has to remember to.
    /**
     * @param  array<string, mixed>  $response
     */
    public static function fromWire(array $response): self
    {
        $counts = [];

        foreach (is_array($response['withheld'] ?? null) ? $response['withheld'] : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $deviceId = $entry['device_id'] ?? null;
            $count = $entry['count'] ?? null;

            if (is_string($deviceId) && $deviceId !== '' && is_int($count)) {
                $counts[$deviceId] = max(0, $count);
            }
        }

        return new self($counts, self::introductionsFromWire($response['introductions'] ?? null));
    }

    /**
     * @return list<array{device_id: string, name: string, ed25519_public_key_hex: string}>
     */
    private static function introductionsFromWire(mixed $raw): array
    {
        $introductions = [];

        foreach (is_array($raw) ? $raw : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $deviceId = $entry['device_id'] ?? null;
            $name = $entry['name'] ?? null;
            $keyHex = $entry['ed25519_public_key_hex'] ?? null;

            if (! is_string($deviceId) || $deviceId === '' || ! is_string($name) || ! is_string($keyHex)) {
                continue;
            }

            if (strlen($keyHex) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES * 2
                || ! ctype_xdigit($keyHex)
                || strtolower($keyHex) !== $keyHex) {
                continue;
            }

            $introductions[] = ['device_id' => $deviceId, 'name' => $name, 'ed25519_public_key_hex' => $keyHex];
        }

        return $introductions;
    }
}
