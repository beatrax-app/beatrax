<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Services;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\SessionFactory;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Tax\Internal\Services\TaxPdfRenderer as InternalTaxPdfRenderer;
use Modules\Tax\Internal\Services\TaxYearQuery as InternalTaxYearQuery;

final class TaxPdfRenderer
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly ViewFactory $views,
        private readonly SensitiveColumnCodec $codec,
        private readonly SessionFactory $session,
        private readonly CrossCurrencyTotal $fx,
        private readonly BaseCurrency $baseCurrency,
    ) {}

    public function render(User $user, int $year): string
    {
        $internalQuery = new InternalTaxYearQuery($this->db, $this->codec, $this->session, $this->fx, $this->baseCurrency);
        $internalRenderer = new InternalTaxPdfRenderer($internalQuery, $this->views);

        return $internalRenderer->render($user, $year);
    }
}
