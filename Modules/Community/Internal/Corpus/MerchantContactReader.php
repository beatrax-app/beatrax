<?php

declare(strict_types=1);

namespace Modules\Community\Internal\Corpus;

use Modules\Community\Public\Dto\MerchantContactDto;
use Psr\Log\LoggerInterface;

final class MerchantContactReader
{
    public const URL_MAX = 512;

    private const PHONE_MAX = 32;

    private const PHONE_MIN_DIGITS = 3;

    // Separators, not structure: `/` splits area code from subscriber number
    // across the German-speaking web (0732/3400-4000), and typographers reach
    // for an en or em dash where a hyphen was meant (089/12 606 – 0). Both
    // belong to the notation merchants publish; none can escape a `tel:` href.
    private const PHONE_SHAPE = '/^[+(]?[0-9][0-9 ()\.\-\/\x{2013}\x{2014}]*$/u';

    private const EMAIL_MAX = 255;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  array<int|string, mixed>  $raw
     * @param  string  $pattern  identifies the offending row in a rejection warning
     */
    public function read(array $raw, string $pattern): ?MerchantContactDto
    {
        $contact = new MerchantContactDto(
            website: $this->url($raw, 'website', $pattern),
            cancelUrl: $this->url($raw, 'cancel_url', $pattern),
            supportUrl: $this->url($raw, 'support_url', $pattern),
            supportPhone: $this->phone($raw, $pattern),
            supportEmail: $this->email($raw, $pattern),
        );

        return $contact->isEmpty() ? null : $contact;
    }

    /**
     * @param  array<int|string, mixed>  $raw
     */
    private function url(array $raw, string $key, string $pattern): ?string
    {
        // HTTPS-only, and short enough to reach the column verbatim. These
        // values are rendered as links a user clicks to cancel a real
        // contract, so a downgradeable scheme or a silently truncated URL is
        // a user-facing hazard rather than a cosmetic defect.
        $value = self::trimmed($raw, $key);
        if ($value === null) {
            return null;
        }

        if (! str_starts_with($value, 'https://')
            || mb_strlen($value) > self::URL_MAX
            || filter_var($value, FILTER_VALIDATE_URL) === false) {
            $this->reject($key, $pattern);

            return null;
        }

        return $value;
    }

    /**
     * @param  array<int|string, mixed>  $raw
     */
    private function phone(array $raw, string $pattern): ?string
    {
        // Kept in the merchant's own published notation rather than E.164: the
        // service numbers people actually need (0800…, 0900…, short codes) have
        // no E.164 form, so normalising would drop the most useful half of the
        // data. Shape is what stops free text reaching a `tel:` href.

        // The digit floor sits at 3 for the same reason — a 6-digit floor
        // rejected the very short codes this exists to preserve, Sýn's 1414
        // and Elvia's 02024 among them. Shape already excludes free text, so
        // the floor only has to reject a lone stray digit.
        $value = self::trimmed($raw, 'support_phone');
        if ($value === null) {
            return null;
        }

        if (mb_strlen($value) > self::PHONE_MAX
            || preg_match(self::PHONE_SHAPE, $value) !== 1
            || self::digitCount($value) < self::PHONE_MIN_DIGITS) {
            $this->reject('support_phone', $pattern);

            return null;
        }

        return $value;
    }

    /**
     * @param  array<int|string, mixed>  $raw
     */
    private function email(array $raw, string $pattern): ?string
    {
        // `?` and `&` are valid RFC 5322 atext, so FILTER_VALIDATE_EMAIL
        // passes them — but in a `mailto:` href they open a header-injection
        // seam. This mirrors the guard SupportResource::mailtoHref applies to
        // the support corpus, moved a layer earlier so the value never lands.
        $value = self::trimmed($raw, 'support_email');
        if ($value === null) {
            return null;
        }

        if (mb_strlen($value) > self::EMAIL_MAX
            || filter_var($value, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/[\s?&]/', $value) === 1) {
            $this->reject('support_email', $pattern);

            return null;
        }

        return $value;
    }

    private function reject(string $field, string $pattern): void
    {
        $this->logger->warning('MerchantContactReader: dropped an unusable corpus contact value.', [
            'pattern' => $pattern,
            'field' => $field,
        ]);
    }

    private static function digitCount(string $value): int
    {
        return mb_strlen((string) preg_replace('/\D/', '', $value));
    }

    /**
     * @param  array<int|string, mixed>  $raw
     */
    private static function trimmed(array $raw, string $key): ?string
    {
        $value = $raw[$key] ?? null;
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
