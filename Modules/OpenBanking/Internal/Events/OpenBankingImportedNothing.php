<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Events;

final readonly class OpenBankingImportedNothing
{
    public function __construct(
        public int $connectionId,
        public int $userId,
        public int $rowsFetched,
    ) {}
}
