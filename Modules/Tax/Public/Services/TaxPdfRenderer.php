<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Tax\Internal\Services\TaxPdfRenderer as InternalTaxPdfRenderer;
use Modules\Tax\Internal\Services\TaxYearQuery as InternalTaxYearQuery;

/**
 * Public facade for the D-14 PDF export renderer.
 *
 * Delegates all work to the internal implementation so consumers can resolve
 * this class through the IoC container without reaching into Modules\Tax\Internal.
 *
 * This class is the singleton registered in TaxServiceProvider (Plan 03).
 * The internal class carries the full dompdf implementation and is only
 * visible inside the Tax module boundary.
 */
final class TaxPdfRenderer
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly ViewFactory $views,
        private readonly SensitiveColumnCodec $codec,
        private readonly Session $session,
    ) {}

    /**
     * Render and return PDF bytes for the user's tax year.
     */
    public function render(User $user, int $year): string
    {
        $internalQuery = new InternalTaxYearQuery($this->db, $this->codec, $this->session);
        $internalRenderer = new InternalTaxPdfRenderer($internalQuery, $this->views);

        return $internalRenderer->render($user, $year);
    }
}
