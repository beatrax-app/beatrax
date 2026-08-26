<?php

declare(strict_types=1);

namespace Modules\Tax\Internal\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Modules\Core\Models\User;

final class TaxPdfRenderer
{
    public function __construct(
        private readonly TaxYearQuery $query,
        private readonly ViewFactory $views,
    ) {}

    // Returns a string starting with '%PDF-'; an empty string only if
    // dompdf's output() returns null (should not happen in practice).
    public function render(User $user, int $year): string
    {
        $options = new Options;
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        // Embedded, not a PDF core font: Helvetica ships no glyphs, and a
        // reader whose substitute has no euro sign drew one at the core
        // metrics' width, over the first digit of every amount.
        $options->set('defaultFont', 'DejaVu Sans');

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
