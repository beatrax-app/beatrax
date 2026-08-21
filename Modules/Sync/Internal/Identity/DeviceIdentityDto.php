<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Identity;

final readonly class DeviceIdentityDto
{
    // Carries BOTH public and secret key material in hex form. MUST NEVER be
    // persisted to the database — the DB holds public keys only. Secret keys
    // rest only inside the BackupEncryptor-encrypted key-file on disk.
    public function __construct(
        public int $version,
        public string $deviceId,
        public int $userId,
        public string $ed25519SecretKeyHex,
        public string $ed25519PublicKeyHex,
        public string $x25519SecretKeyHex,
        public string $x25519PublicKeyHex,
        public string $createdAt,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            version: self::intField($data, 'v'),
            deviceId: self::stringField($data, 'device_id'),
            userId: self::intField($data, 'user_id'),
            ed25519SecretKeyHex: self::stringField($data, 'ed25519_secret_key_hex'),
            ed25519PublicKeyHex: self::stringField($data, 'ed25519_public_key_hex'),
            x25519SecretKeyHex: self::stringField($data, 'x25519_secret_key_hex'),
            x25519PublicKeyHex: self::stringField($data, 'x25519_public_key_hex'),
            createdAt: self::stringField($data, 'created_at'),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function stringField(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value)) {
            throw new \InvalidArgumentException("DeviceIdentityDto: '{$key}' must be a string.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function intField(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (! is_int($value)) {
            throw new \InvalidArgumentException("DeviceIdentityDto: '{$key}' must be an int.");
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'v' => $this->version,
            'device_id' => $this->deviceId,
            'user_id' => $this->userId,
            'ed25519_secret_key_hex' => $this->ed25519SecretKeyHex,
            'ed25519_public_key_hex' => $this->ed25519PublicKeyHex,
            'x25519_secret_key_hex' => $this->x25519SecretKeyHex,
            'x25519_public_key_hex' => $this->x25519PublicKeyHex,
            'created_at' => $this->createdAt,
        ];
    }
}
