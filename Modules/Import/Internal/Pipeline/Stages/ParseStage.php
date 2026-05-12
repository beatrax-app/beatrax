<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline\Stages;

use Generator;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Services\SourceAdapterRegistry;

/**
 * Thin orchestration wrapper around the SourceAdapterRegistry. Hands the
 * declared format string to the registry, looks up the matching adapter,
 * and yields its lazy stream of SourceTransactionDto rows without
 * materialising them.
 */
final class ParseStage
{
    public function __construct(private readonly SourceAdapterRegistry $registry) {}

    /**
     * @return Generator<int, SourceTransactionDto>
     */
    public function run(string $localPath, string $sourceFormat, AccountResolver $accounts): Generator
    {
        yield from $this->registry->for($sourceFormat)->parse($localPath, $accounts);
    }
}
