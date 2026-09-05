<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Exceptions;

use Modules\Core\Public\Support\Lang;
use RuntimeException;
use Throwable;

// Distinct from an API failure: no bank is involved and no retry helps: the
// user finishes the wizard, or the on-disk file is repaired. Two controllers
// flash what they catch, so the reader's half of each refusal is carried here
// rather than left to whichever caller happens to hold one.
final class OpenBankingCredentialsException extends RuntimeException
{
    private function __construct(string $message, private readonly string $readerKey, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function notConfigured(): self
    {
        return new self(
            'No Enable Banking application credentials are persisted.',
            'openbanking::messages.errors.wizard_incomplete',
        );
    }

    // The reader holds an application but no consent for THIS bank: the row
    // outlived its session material, or a peer's row arrived without one.
    public static function bankNotLinked(string $institutionId): self
    {
        return new self(
            "No Enable Banking consent is stored for institution {$institutionId}.",
            'openbanking::messages.errors.bank_not_linked',
        );
    }

    // Only the path: the decoded or raw payload would leak credential material
    // into every logging surface above this. The path is a developer's detail
    // too -- it reached the settings screen verbatim, in English.
    public static function unreadable(string $path, Throwable $previous): self
    {
        return new self(
            "Failed to parse the Enable Banking secrets file at {$path}.",
            'openbanking::messages.page.credentials_unreadable',
            $previous,
        );
    }

    public function readerMessage(): string
    {
        return Lang::get($this->readerKey);
    }
}
