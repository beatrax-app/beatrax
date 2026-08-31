<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Exceptions;

use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\MessageNamesNoUserData;
use Modules\Ingestion\Public\Contracts\NamesAFormatMismatch;
use Modules\Ingestion\Public\Enums\SourceFormat;
use RuntimeException;

// The bytes are not the receipt transport the run declared. Which one they
// actually are decides the whole message and the whole loss: an archive read
// as a single message keeps its first message and drops every other, and a
// single message read as an archive yields nothing at all.
final class ReceiptFormatMismatchException extends RuntimeException implements MessageNamesNoUserData, NamesAFormatMismatch
{
    public static function found(?SourceFormat $found): self
    {
        return new self(Lang::get(match ($found) {
            SourceFormat::Mbox => 'import::preview.errors.email_file_is_an_archive',
            SourceFormat::Eml => 'import::preview.errors.archive_holds_one_message',
            default => 'import::preview.errors.not_an_email_file',
        }));
    }
}
