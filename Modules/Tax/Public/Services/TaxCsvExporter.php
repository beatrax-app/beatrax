<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Tax\Internal\Services\TaxCsvExporter as InternalTaxCsvExporter;
use Modules\Tax\Internal\Services\TaxYearQuery as InternalTaxYearQuery;

/**
 * @link ../../../../.docs/features/tax/architecture.md
 */
final class TaxCsvExporter
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SensitiveColumnCodec $codec,
        private readonly SessionFactory $session,
    ) {}

    public function export(User $user, int $year): string
    {
        $internalQuery = new InternalTaxYearQuery($this->db, $this->codec, $this->session);
        $internalExporter = new InternalTaxCsvExporter($internalQuery);

        return $internalExporter->export($user, $year);
    }
}
