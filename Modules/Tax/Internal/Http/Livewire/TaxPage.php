<?php

declare(strict_types=1);

namespace Modules\Tax\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Tax\Public\Services\TaxCsvExporter;
use Modules\Tax\Public\Services\TaxPdfRenderer;
use Modules\Tax\Public\Services\TaxYearQuery;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @link ../../../../../.docs/features/tax/architecture.md
 */
final class TaxPage extends Component
{
    // 0 means "use seasonal default," resolved in mount(). #[Url] so deep
    // links (?year=2025) and the back button work.
    #[Url(as: 'year', except: 0)]
    public int $year = 0;

    public function mount(CurrentUser $currentUser, Clock $clock): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        if ($this->year === 0) {
            $now = $clock->now();
            $this->year = $now->month <= 4
                ? $now->year - 1
                : $now->year;
        }
    }

    public function exportCsv(
        ResponseFactory $responses,
        TaxCsvExporter $exporter,
        CurrentUser $currentUser,
    ): StreamedResponse {
        if (! $currentUser->isAuthenticated()) {
            return new StreamedResponse(static function (): void {
                // Guests get an empty 200 body rather than a download; the
                // route's 'auth' middleware already makes this unreachable in
                // practice, so nothing is streamed here.
            });
        }

        $year = $this->year;
        $user = $currentUser->user();

        return $responses->streamDownload(
            static function () use ($exporter, $user, $year): void {
                echo $exporter->export($user, $year);
            },
            "beatrax-tax-{$year}.csv",
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    public function exportPdf(
        ResponseFactory $responses,
        TaxPdfRenderer $renderer,
        CurrentUser $currentUser,
    ): StreamedResponse {
        if (! $currentUser->isAuthenticated()) {
            return new StreamedResponse(static function (): void {
                // Guests get an empty 200 body rather than a download; the
                // route's 'auth' middleware already makes this unreachable in
                // practice, so nothing is streamed here.
            });
        }

        $year = $this->year;
        $user = $currentUser->user();

        return $responses->streamDownload(
            static function () use ($renderer, $user, $year): void {
                echo $renderer->render($user, $year);
            },
            "beatrax-tax-{$year}.pdf",
            ['Content-Type' => 'application/pdf'],
        );
    }

    public function render(
        CurrentUser $currentUser,
        TaxYearQuery $query,
        ViewFactory $views,
        DatabaseManager $db,
    ): View {
        // Defense-in-depth: the route group's 'auth' middleware makes this
        // unreachable for guests, but guard anyway so an unauthenticated
        // render degrades gracefully.
        if (! $currentUser->isAuthenticated()) {
            $view = $views->make('tax::livewire.tax-page', [
                'data' => null,
                'availableYears' => [],
                'hasTaxCountry' => false,
            ]);

            /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
            $view->extends('layouts.app', ['title' => 'Tax · beatrax']);

            return $view;
        }

        $user = $currentUser->user();
        $data = $query->forUser($user->id, $this->year);
        $availableYears = $query->availableYears($user->id);

        // Read from the DB, not the model, since tax_country_code is not
        // typed on User; TaxSettingsSection writes it via DatabaseManager
        // directly.
        $taxCountryCode = $db->connection()
            ->table('users')
            ->where('id', $user->id)
            ->value('tax_country_code');

        $hasTaxCountry = is_string($taxCountryCode) && $taxCountryCode !== '';

        $view = $views->make('tax::livewire.tax-page', [
            'data' => $data,
            'availableYears' => $availableYears,
            'hasTaxCountry' => $hasTaxCountry,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => "Tax {$this->year} · beatrax"]);

        return $view;
    }
}
