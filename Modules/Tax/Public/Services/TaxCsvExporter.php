<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Tax\Internal\Services\TaxCsvExporter as InternalTaxCsvExporter;
use Modules\Tax\Internal\Services\TaxYearQuery as InternalTaxYearQuery;

/**
 * Public facade for the D-15 audit-extra CSV export service.
 *
 * Delegates all work to the internal implementation so consumers can resolve
 * this class through the IoC container without reaching into Modules\Tax\Internal.
 *
 * This class is the singleton registered in TaxServiceProvider (Plan 03).
 * The internal class carries the full implementation and is only visible
 * inside the Tax module boundary.
 */
final class TaxCsvExporter
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SensitiveColumnCodec $codec,
        private readonly Session $session,
    ) {}

    /**
     * Build and return the full D-15 audit CSV body for the user and year.
     *
     * Returns a header-only CSV when no tagged transactions exist.
     */
    public function export(User $user, int $year): string
    {
        $internalQuery = new InternalTaxYearQuery($this->db, $this->codec, $this->session);
        $internalExporter = new InternalTaxCsvExporter($internalQuery);

        return $internalExporter->export($user, $year);
    }
}
