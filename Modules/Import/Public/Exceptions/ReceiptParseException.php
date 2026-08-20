<?php

declare(strict_types=1);

namespace Modules\Import\Public\Exceptions;

use RuntimeException;
use Throwable;

// Either a receipt format arrived without the User context its recorder
// needs, or the bytes could not be read. Both abort the parse before any
// SourceTransactionDto is yielded.
/**
 * @link ../../../../.docs/architecture/ingestion-pipeline.md#1-parse-parsestage
 */
final class ReceiptParseException extends RuntimeException
{
    public static function missingUserContext(string $sourceFormat): self
    {
        return new self(sprintf(
            "ParseStage: sourceFormat '%s' requires a User context.",
            $sourceFormat,
        ));
    }

    public static function unreadable(string $localPath, Throwable $previous): self
    {
        return new self(
            sprintf('ParseStage: cannot read .eml at %s.', $localPath),
            previous: $previous,
        );
    }
}
