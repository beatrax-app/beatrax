<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Identity;

/**
 * @see BiometricKeyVault::recover()
 */
final readonly class BiometricRecoverResult
{
    public const string RECOVERED = 'recovered';

    public const string PENDING_ASYNC = 'pending_async';

    public const string CANCELED = 'canceled';

    // Biometric authentication actively failed (wrong finger, lockout) or
    // a transient native error - distinct from MISSING (nothing
    // enrolled). The device IS enrolled; the caller must stay put and
    // offer the PIN, never conclude "no cold-start credential exists".
    public const string FAILED = 'failed';

    public const string MISSING = 'missing';

    public const string UNAVAILABLE = 'unavailable';

    private function __construct(
        public string $status,
        public ?string $dataKey = null,
    ) {}

    public static function recovered(string $dataKey): self
    {
        return new self(self::RECOVERED, $dataKey);
    }

    public static function pendingAsync(): self
    {
        return new self(self::PENDING_ASYNC);
    }

    public static function canceled(): self
    {
        return new self(self::CANCELED);
    }

    public static function failed(): self
    {
        return new self(self::FAILED);
    }

    public static function missing(): self
    {
        return new self(self::MISSING);
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE);
    }

    public function isRecovered(): bool
    {
        return $this->status === self::RECOVERED && is_string($this->dataKey);
    }
}
