<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Services;

// import_runs.raw_file_path is a required audit string with no FK to storage,
// so a bank-fetched window carries this marker where an upload carries a path.
// Writer and readers share the scheme here: handed to hash_file(), the marker
// raised "unable to find the wrapper open-banking" on the account-naming screen.
final class RemoteFetchPath
{
    private const string SCHEME = 'open-banking://';

    public static function forKey(string $idempotencyKey): string
    {
        return self::SCHEME.$idempotencyKey;
    }

    public static function isRemote(string $rawFilePath): bool
    {
        return str_starts_with($rawFilePath, self::SCHEME);
    }
}
