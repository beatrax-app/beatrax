<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Tax\Internal\Services\TaxYearQuery as InternalTaxYearQuery;
use Modules\Tax\Public\Dto\TaxYearData;

/**
 * @link ../../../../.docs/features/tax/architecture.md
 */
final class TaxYearQuery
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SensitiveColumnCodec $codec,
        private readonly Session $session,
    ) {}

    /**
     * @see InternalTaxYearQuery::forUser()
     */
    public function forUser(int $userId, int $year): TaxYearData
    {
        return (new InternalTaxYearQuery($this->db, $this->codec, $this->session))->forUser($userId, $year);
    }

    /**
     * @return array<int>
     */
    public function availableYears(int $userId): array
    {
        return (new InternalTaxYearQuery($this->db, $this->codec, $this->session))->availableYears($userId);
    }
}
