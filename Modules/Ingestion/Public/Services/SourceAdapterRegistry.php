<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Services;

use Modules\Ingestion\Public\Contracts\SourceAdapter;
use Modules\Ingestion\Public\Exceptions\UnsupportedFormatException;

/**
 * Map of stable format identifier → SourceAdapter. The Import pipeline
 * (Plan 05) injects this registry to look up the right adapter for whatever
 * the user declared at upload time.
 *
 * Adapters are NOT auto-detected from file content (per ING-07) — the user
 * declares the source format up front in the upload wizard. HeaderSniffer
 * then validates the file matches that declared format.
 */
final class SourceAdapterRegistry
{
    /** @param array<string, SourceAdapter> $byFormat */
    public function __construct(private readonly array $byFormat) {}

    public function for(string $format): SourceAdapter
    {
        if (! isset($this->byFormat[$format])) {
            throw new UnsupportedFormatException(sprintf(
                "No source adapter registered for format '%s'. Supported: %s",
                $format,
                implode(', ', $this->supportedFormats()),
            ));
        }

        return $this->byFormat[$format];
    }

    /** @return array<int, string> */
    public function supportedFormats(): array
    {
        return array_keys($this->byFormat);
    }
}
