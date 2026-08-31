<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Modules\OpenBanking\Internal\Enums\ConsentStatus;

// The one place the "is this consent still usable?" boundary is decided, for
// the four callers that each used to decide it for themselves.
/**
 * @link ../../../../.docs/features/open-banking/consent-window.md
 */
final readonly class ConsentWindow
{
    public const int VALID_FOR_DAYS = 180;

    // Warned while still fully live: re-linking costs a browser round trip to
    // the bank, so the warning needs room to act on.
    public const int EXPIRING_SOON_DAYS = 14;

    private function __construct(
        private ?CarbonImmutable $expiresAt,
        private ?CarbonImmutable $revokedAt,
        private CarbonImmutable $now,
    ) {}

    public static function endingAt(?CarbonImmutable $expiresAt, CarbonImmutable $now, ?CarbonImmutable $revokedAt = null): self
    {
        return new self($expiresAt, $revokedAt, $now);
    }

    // The whole row, not one column of it: the end of the window and the bank
    // having taken it away are two independent facts, and a door that takes
    // only the first is a door a caller forgets the second at.
    public static function fromStoredRow(object $row, CarbonImmutable $now): self
    {
        return new self(
            self::parse($row->consent_expires_at ?? null),
            self::parse($row->consent_revoked_at ?? null),
            $now,
        );
    }

    public static function expiresAfter(CarbonImmutable $issuedAt): CarbonImmutable
    {
        return $issuedAt->addDays(self::VALID_FOR_DAYS);
    }

    // The same boundary as isLive(), expressed where the rows are: the daily
    // scheduler enumerates every connection there is and must not load them.
    public static function constrainToLive(Builder $query, CarbonImmutable $now): Builder
    {
        return $query
            ->whereNull('consent_revoked_at')
            ->whereNotNull('consent_expires_at')
            ->where('consent_expires_at', '>', $now->toDateTimeString());
    }

    public function isLive(): bool
    {
        return ! $this->isRevoked() && $this->expiresAt !== null && $this->expiresAt->greaterThan($this->now);
    }

    // The aggregator refusing the session outranks the calendar: a window with
    // months left on it is worth nothing once the bank has withdrawn it.
    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isExpiringSoon(): bool
    {
        $expiresAt = $this->expiresAt;

        return ! $this->isRevoked()
            && $expiresAt !== null
            && $expiresAt->greaterThan($this->now)
            && $expiresAt->lessThanOrEqualTo($this->now->addDays(self::EXPIRING_SOON_DAYS));
    }

    public function status(): ConsentStatus
    {
        return match (true) {
            $this->isRevoked() => ConsentStatus::Revoked,
            ! $this->isLive() => ConsentStatus::Expired,
            $this->isExpiringSoon() => ConsentStatus::Expiring,
            default => ConsentStatus::Connected,
        };
    }

    private static function parse(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}
