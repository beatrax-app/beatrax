<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Tax\Internal\Services\TaxYearQuery as InternalTaxYearQuery;
use Modules\Tax\Public\Dto\TaxYearData;

/**
 * Public facade for the grouped tax-year query service.
 *
 * Delegates all work to the internal implementation so consumers
 * (including TaxPage, exporters, and the year-switcher) can resolve this
 * class through the IoC container without reaching into Modules\Tax\Internal.
 *
 * This class is the singleton registered in TaxServiceProvider; the internal
 * class carries the full query logic and is only visible inside the Tax module.
 */
final class TaxYearQuery
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SensitiveColumnCodec $codec,
        private readonly Session $session,
    ) {}

    /**
     * Return grouped tax-year data for a user and effective tax year.
     *
     * @see InternalTaxYearQuery::forUser()
     */
    public function forUser(int $userId, int $year): TaxYearData
    {
        return (new InternalTaxYearQuery($this->db, $this->codec, $this->session))->forUser($userId, $year);
    }

    /**
     * Return distinct effective tax years for a user in descending order.
     *
     * @return array<int>
     */
    public function availableYears(int $userId): array
    {
        return (new InternalTaxYearQuery($this->db, $this->codec, $this->session))->availableYears($userId);
    }
}
