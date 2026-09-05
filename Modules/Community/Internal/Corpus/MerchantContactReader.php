<?php

declare(strict_types=1);

namespace Modules\Community\Internal\Corpus;

use Modules\Community\Internal\Support\RecipientAddress;
use Modules\Community\Public\Dto\MerchantContactDto;
use Modules\Core\Public\Enums\ExternalUrlRefusal;
use Modules\Core\Public\Support\ExternalUrl;
use Modules\Core\Public\Support\PatternScan;
use Psr\Log\LoggerInterface;

final readonly class MerchantContactReader
{
    private const int PHONE_MAX = 32;

    private const int PHONE_MIN_DIGITS = 3;

    // Separators, not structure: `/` splits area code from subscriber number
    // across the German-speaking web (0732/3400-4000), and typographers reach
    // for an en or em dash where a hyphen was meant (089/12 606 – 0).
    private const string PHONE_SHAPE = '/^[+(]?[0-9][0-9 ()\.\-\/\x{2013}\x{2014}]*$/u';

    public function __construct(
        private LoggerInterface $logger,
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
        // Judged by the same gate as every other externally-supplied URL: these
        // render as links a user clicks to cancel a real contract, and this
        // reader used to carry its own copy of the rules while the templates
        // downstream of it carried a laxer one.
        $value = self::trimmed($raw, $key);
        if ($value === null) {
            return null;
        }

        $refusal = ExternalUrl::refusalFor($value);
        if ($refusal !== null) {
            $this->reject($key, $pattern, $refusal);

            return null;
        }

        return $value;
    }

    /**
     * @param  array<int|string, mixed>  $raw
     */
    private function phone(array $raw, string $pattern): ?string
    {
        // Kept in the merchant's published notation rather than E.164: the
        // service numbers people need (0800…, 0900…, short codes) have no
        // E.164 form, so normalising would drop the most useful half.

        // The digit floor is 3 because a 6-digit floor rejected the short
        // codes this exists to preserve — Sýn's 1414, Elvia's 02024.
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
        // `?`, `&` and `,` are all valid RFC 5322 atext, so FILTER_VALIDATE_EMAIL
        // passes them — but in a `mailto:` href they forge headers and extra
        // recipients. RecipientAddress is the same gate mailtoHref applies.
        $value = self::trimmed($raw, 'support_email');
        if ($value === null) {
            return null;
        }

        if (! RecipientAddress::isSingle($value)) {
            $this->reject('support_email', $pattern);

            return null;
        }

        return $value;
    }

    private function reject(string $field, string $pattern, ?ExternalUrlRefusal $refusal = null): void
    {
        $this->logger->warning('MerchantContactReader: dropped an unusable corpus contact value.', [
            'pattern' => $pattern,
            'field' => $field,
            'refusal' => $refusal?->value,
        ]);
    }

    private static function digitCount(string $value): int
    {
        return mb_strlen(PatternScan::replace('/\D/', '', $value));
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
