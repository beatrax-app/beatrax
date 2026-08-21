<?php

declare(strict_types=1);

namespace Modules\Tax\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\UserCountry;
use Modules\Core\Public\Support\Lang;
use Modules\Tax\Internal\Support\FilingSeason;
use Modules\Tax\Public\Services\TaxCsvExporter;
use Modules\Tax\Public\Services\TaxPdfRenderer;
use Modules\Tax\Public\Services\TaxYearQuery;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TaxPage extends Component
{
    // 0 means "use the seasonal default", resolved in mount(). #[Url] keeps
    // ?year=2025 deep links and the back button working.
    #[Url(as: 'year', except: 0)]
    public int $year = 0;

    public function mount(CurrentUser $currentUser, Clock $clock): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        if ($this->year === 0) {
            $this->year = FilingSeason::defaultYear($clock->now());
        }
    }

    public function exportCsv(
        ResponseFactory $responses,
        TaxCsvExporter $exporter,
        CurrentUser $currentUser,
    ): StreamedResponse {
        if (! $currentUser->isAuthenticated()) {
            return new StreamedResponse(static function (): void {
                // Unreachable behind the route's 'auth' middleware; a guest
                // gets an empty 200 rather than a download.
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
                // Unreachable behind the route's 'auth' middleware; a guest
                // gets an empty 200 rather than a download.
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
        UserCountry $countries,
    ): View {
        // Unreachable behind the route group's 'auth' middleware; kept so an
        // unauthenticated render degrades to the empty page instead of throwing.
        if (! $currentUser->isAuthenticated()) {
            $view = $views->make('tax::livewire.tax-page', [
                'data' => null,
                'availableYears' => [],
                'hasTaxCountry' => false,
            ]);

            /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
            $view->extends('layouts.app', ['title' => Lang::get('tax::page.page_title').' · Beatrax']);

            return $view;
        }

        $user = $currentUser->user();
        $data = $query->forUser($user->id, $this->year);
        $availableYears = $query->availableYears($user->id);

        $hasTaxCountry = $countries->current($user->id) !== '';

        $view = $views->make('tax::livewire.tax-page', [
            'data' => $data,
            'availableYears' => $availableYears,
            'hasTaxCountry' => $hasTaxCountry,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('tax::page.page_title_year', ['year' => $this->year]).' · Beatrax']);

        return $view;
    }
}
