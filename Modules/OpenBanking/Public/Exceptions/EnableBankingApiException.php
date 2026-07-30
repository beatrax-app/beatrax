<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Public\Exceptions;

use RuntimeException;
use Throwable;

// The Enable Banking API was reached but did not yield usable data: a
// transport error, a non-2xx status, a body that would not decode, or one
// missing a field the caller cannot synthesise. Carries the HTTP status,
// because whether the failure is terminal turns on it — isConsentFailure().
/**
 * @link ../../../../.docs/features/open-banking/architecture.md
 */
final class EnableBankingApiException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function transportFailed(string $context, Throwable $previous): self
    {
        return new self(
            "Enable Banking transport error against {$context} — {$previous->getMessage()}",
            previous: $previous,
        );
    }

    public static function errorStatus(string $context, int $status, string $bodySnippet): self
    {
        return new self("Enable Banking {$context} returned HTTP {$status} — {$bodySnippet}", $status);
    }

    public static function malformedJson(string $context, Throwable $previous): self
    {
        return new self(
            "Enable Banking response from {$context} did not decode as JSON ({$previous->getMessage()}).",
            previous: $previous,
        );
    }

    public static function missingOwnAccountIban(): self
    {
        return new self('Enable Banking accountDetails() carried no own-account IBAN to resolve.');
    }

    public static function missingTransactionField(string $fieldName): self
    {
        return new self(
            "Enable Banking transaction row is missing {$fieldName}; refusing to derive a "
            .'fingerprinted date from the wall clock.',
        );
    }

    // 401 and 403 both mean the consent behind this connection is no longer
    // usable, which no retry can repair — only the user redoing the re-link
    // dance can. Every other status is worth attempting again.
    public function isConsentFailure(): bool
    {
        return $this->status === 401 || $this->status === 403;
    }

    // The rule lives here so the two places that act on it — the sync job and
    // the settings page — cannot drift apart. Walks the chain because the
    // import pipeline between the provider call and either catch site is free
    // to wrap what it rethrows.
    public static function consentFailureWithin(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof self && $current->isConsentFailure()) {
                return true;
            }
        }

        return false;
    }
}
