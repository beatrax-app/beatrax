<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Public\Exceptions;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

// The API was reached but yielded nothing usable. Carries the HTTP status,
// because whether the failure is terminal turns on it.
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

    // 401 and 403 mean the consent is no longer usable and no retry repairs
    // that; every other status is worth attempting again.
    public function isConsentFailure(): bool
    {
        return $this->status === Response::HTTP_UNAUTHORIZED || $this->status === Response::HTTP_FORBIDDEN;
    }

    // Walks the chain: the import pipeline between the provider call and either
    // catch site is free to wrap what it rethrows.
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
