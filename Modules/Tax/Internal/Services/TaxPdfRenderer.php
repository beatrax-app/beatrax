<?php

declare(strict_types=1);

namespace Modules\Tax\Internal\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Modules\Core\Models\User;

/**
 * Renders a PDF summary of tax-tagged transactions for a user's tax year.
 *
 * Follows RESEARCH.md Pattern 6 (dompdf v3):
 *  - isHtml5ParserEnabled=true for modern HTML parsing.
 *  - isRemoteEnabled=false — local-only app; no remote CSS/image fetches (T-07-09).
 *  - defaultFont=Helvetica — bundled, no network fetch required.
 *
 * The PDF Blade template (tax::pdf.export) uses CSS 2.1 table-only layout.
 * No Tailwind, no Flexbox, no Grid — dompdf CSS 2.1 does not support them.
 * All dynamic values in the template use {{ }} (Blade auto-escaping) to
 * prevent XSS injection from free-text notes (T-07-08).
 *
 * T-07-10: reads only TaxYearQuery::forUser($user->id, $year) — user-scoped.
 */
final class TaxPdfRenderer
{
    public function __construct(
        private readonly TaxYearQuery $query,
        private readonly ViewFactory $views,
    ) {}

    /**
     * Render and return PDF bytes for the user's tax year.
     *
     * Returns a string starting with '%PDF-'. Returns an empty string only
     * if dompdf's output() returns null (should not happen in practice).
     */
    public function render(User $user, int $year): string
    {
        $options = new Options;
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Helvetica');

        $pdf = new Dompdf($options);

        $data = $this->query->forUser($user->id, $year);

        $html = $this->views->make('tax::pdf.export', [
            'year' => $year,
            'data' => $data,
        ])->render();

        $pdf->loadHtml($html);
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();

        return $pdf->output();
    }
}
