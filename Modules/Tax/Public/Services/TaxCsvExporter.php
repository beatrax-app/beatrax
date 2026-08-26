<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\SessionFactory;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Tax\Internal\Services\TaxCsvExporter as InternalTaxCsvExporter;
use Modules\Tax\Internal\Services\TaxYearQuery as InternalTaxYearQuery;

final class TaxCsvExporter
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SensitiveColumnCodec $codec,
        private readonly SessionFactory $session,
        private readonly CrossCurrencyTotal $fx,
        private readonly BaseCurrency $baseCurrency,
    ) {}

    public function export(User $user, int $year): string
    {
        $internalQuery = new InternalTaxYearQuery($this->db, $this->codec, $this->session, $this->fx, $this->baseCurrency);
        $internalExporter = new InternalTaxCsvExporter($internalQuery);

        return $internalExporter->export($user, $year);
    }
}
