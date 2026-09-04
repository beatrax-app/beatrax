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
use Modules\Core\Public\Http\Livewire\Concerns\HoldsFlashMessage;
use Modules\Core\Public\Services\UserCountry;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Public\Services\ShareSheetExport;
use Modules\Tax\Internal\Services\TaxCsvExporter;
use Modules\Tax\Internal\Services\TaxPdfRenderer;
use Modules\Tax\Internal\Services\TaxYearQuery;
use Modules\Tax\Internal\Support\FilingSeason;
use Modules\Tax\Internal\Support\TaxYearBounds;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TaxPage extends Component
{
    use HoldsFlashMessage;

    // The window title's tail, separated by a middle dot rather than a dash,
    // which is what every other titled page here uses.
    private const string TITLE_SUFFIX = ' · Beatrax';

    // 0 means "use the seasonal default", resolved in mount(). #[Url] keeps
    // ?year=2025 deep links and the back button working.
    #[Url(as: 'year', except: 0)]
    public int $year = 0;

    public function mount(CurrentUser $currentUser, Clock $clock): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $this->year = $this->year === 0
            ? FilingSeason::defaultYear($clock->now())
            : TaxYearBounds::clamp($this->year, $clock->now()->year);
    }

    public function exportCsv(
        ResponseFactory $responses,
        TaxCsvExporter $exporter,
        CurrentUser $currentUser,
        ShareSheetExport $shareSheet,
    ): ?StreamedResponse {
        if (! $currentUser->isAuthenticated()) {
            return new StreamedResponse(static function (): void {
                // Unreachable behind the route's 'auth' middleware; a guest
                // gets an empty 200 rather than a download.
            });
        }

        $year = $this->year;
        $user = $currentUser->user();

        if ($shareSheet->replacesWebViewDownload()) {
            return $this->handOver($shareSheet, "beatrax-tax-{$year}.csv", $exporter->export($user, $year));
        }

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
        ShareSheetExport $shareSheet,
    ): ?StreamedResponse {
        if (! $currentUser->isAuthenticated()) {
            return new StreamedResponse(static function (): void {
                // Unreachable behind the route's 'auth' middleware; a guest
                // gets an empty 200 rather than a download.
            });
        }

        $year = $this->year;
        $user = $currentUser->user();

        if ($shareSheet->replacesWebViewDownload()) {
            return $this->handOver($shareSheet, "beatrax-tax-{$year}.pdf", $renderer->render($user, $year));
        }

        return $responses->streamDownload(
            static function () use ($renderer, $user, $year): void {
                echo $renderer->render($user, $year);
            },
            "beatrax-tax-{$year}.pdf",
            ['Content-Type' => 'application/pdf'],
        );
    }

    public function clearFlash(): void
    {
        $this->flashMessage = '';
    }

    // Returns null rather than a response: on a shell whose WebView drops a
    // download there is nothing to send, and the OS sheet plus this line is
    // what the reader gets instead of a page that did not change.
    private function handOver(ShareSheetExport $shareSheet, string $filename, string $contents): null
    {
        $this->flashMessage = $shareSheet->export($filename, $contents)->message();

        return null;
    }

    public function render(
        CurrentUser $currentUser,
        TaxYearQuery $query,
        ViewFactory $views,
        UserCountry $countries,
        Clock $clock,
    ): View {
        // Unreachable behind the route group's 'auth' middleware; kept so an
        // unauthenticated render degrades to the empty page instead of throwing.
        if (! $currentUser->isAuthenticated()) {
            $view = $views->make('tax::livewire.tax-page', [
                'data' => null,
                'availableYears' => [],
                'hasCountry' => false,
                'documentTitle' => Lang::get('tax::page.page_title').self::TITLE_SUFFIX,
            ]);

            $view->extends('layouts.app', ['title' => Lang::get('tax::page.page_title').self::TITLE_SUFFIX]);

            return $view;
        }

        // Sanitised on the way out as well as in mount(): #[Url] is one door on
        // this property and the switcher's $set is another, and only the first
        // of them passes through mount().
        $this->year = TaxYearBounds::clamp($this->year, $clock->now()->year);

        $user = $currentUser->user();
        $data = $query->forUser($user->id, $this->year);
        $availableYears = $query->availableYears($user->id);

        $hasCountry = $countries->current($user->id) !== '';

        // One string for both the layout's <title> and the blade's re-titling
        // hook, so a switched year cannot leave them naming different years.
        $documentTitle = Lang::get('tax::page.page_title_year', ['year' => $this->year]).self::TITLE_SUFFIX;

        $view = $views->make('tax::livewire.tax-page', [
            'data' => $data,
            'availableYears' => $availableYears,
            'hasCountry' => $hasCountry,
            'documentTitle' => $documentTitle,
        ]);

        $view->extends('layouts.app', ['title' => $documentTitle]);

        return $view;
    }
}
