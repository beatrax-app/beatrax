<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Services;

use Modules\Ingestion\Public\Contracts\SourceAdapter;
use Modules\Ingestion\Public\Exceptions\UnsupportedFormatException;

final class SourceAdapterRegistry
{
    /** @param array<string, SourceAdapter> $byFormat */
    public function __construct(private readonly array $byFormat) {}

    /** @throws UnsupportedFormatException */
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
