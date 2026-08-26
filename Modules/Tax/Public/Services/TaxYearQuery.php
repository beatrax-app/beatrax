<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Services\SessionFactory;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Tax\Internal\Services\TaxYearQuery as InternalTaxYearQuery;
use Modules\Tax\Public\Dto\TaxYearData;

final class TaxYearQuery
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SensitiveColumnCodec $codec,
        private readonly SessionFactory $session,
        private readonly CrossCurrencyTotal $fx,
        private readonly BaseCurrency $baseCurrency,
    ) {}

    /**
     * @see InternalTaxYearQuery::forUser()
     */
    public function forUser(int $userId, int $year): TaxYearData
    {
        return (new InternalTaxYearQuery($this->db, $this->codec, $this->session, $this->fx, $this->baseCurrency))->forUser($userId, $year);
    }

    /**
     * @return array<int>
     */
    public function availableYears(int $userId): array
    {
        return (new InternalTaxYearQuery($this->db, $this->codec, $this->session, $this->fx, $this->baseCurrency))->availableYears($userId);
    }
}
