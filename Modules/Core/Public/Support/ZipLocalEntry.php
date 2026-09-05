<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

/**
 * @link ../../../../.docs/features/migration/reading-a-zip-without-ext-zip.md
 */
final class ZipLocalEntry
{
    public const string SIGNATURE = "PK\x03\x04";

    // The fixed part of a local file header, before the variable-length name
    // and extra field. It is a fact about the format rather than a choice, and
    // the two readers here disagreeing about it would put one of them a few
    // bytes into somebody's payload.
    public const int FIXED_BYTES = 30;

    public const int METHOD_STORE = 0;

    public const int METHOD_DEFLATE = 8;

    // How much of an archive is pulled into memory at a time. Both readers
    // stream rather than slurp, because the entry on the other side of this
    // number is a whole database.
    public const int READ_CHUNK_BYTES = 262144;
}
