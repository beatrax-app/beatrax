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
 * @link ../../../../.docs/features/tax/architecture.md
 */
final class TaxPdfRenderer
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly ViewFactory $views,
        private readonly SensitiveColumnCodec $codec,
        private readonly Session $session,
    ) {}

    public function render(User $user, int $year): string
    {
        $internalQuery = new InternalTaxYearQuery($this->db, $this->codec, $this->session);
        $internalRenderer = new InternalTaxPdfRenderer($internalQuery, $this->views);

        return $internalRenderer->render($user, $year);
    }
}
