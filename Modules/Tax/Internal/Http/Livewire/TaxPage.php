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
 * `/tax` year cockpit — year switcher, grouped category sections,
 * export buttons, and guided empty states.
 *
 * Method-parameter DI on every action and on mount/render — constructor
 * injection is banned on Livewire Component subclasses by phpstan-strict-rules
 * (same pattern as GoalsPage, DriftPage, TaxSettingsSection).
 *
 * TAX-02 / D-09: Grouped deduction-category sections with per-category
 * subtotals + grand totals in settled EUR.
 * TAX-03 / D-16: Export CSV and Export PDF stream-download buttons.
 * D-22: Seasonal year default — Jan–Apr → previous year; May–Dec → current year.
 * D-23: Guided empty states (first-visit + empty-year).
 * T-07-16: exportCsv/exportPdf pass CurrentUser to user-scoped services only.
 * T-07-17: Unauthenticated render branch returns an empty view (route group
 *   adds 'auth' middleware; this is a defence-in-depth fallback).
 */
final class TaxPage extends Component
{
    /**
     * Active tax year. 0 means "use seasonal default" — resolved in mount().
     * #[Url] so deep links (?year=2025) and back-button work (D-22).
     */
    #[Url(as: 'year', except: 0)]
    public int $year = 0;

    public function mount(CurrentUser $currentUser, Clock $clock): void
    {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        if ($this->year === 0) {
            $now = $clock->now();
            // D-22: Jan–Apr = aangifte season → previous year; May–Dec → current year.
            $this->year = $now->month <= 4
                ? $now->year - 1
                : $now->year;
        }
    }

    /**
     * Export tagged transactions for the current year as a D-15 audit CSV.
     * T-07-16: passes the acting user to the user-scoped exporter only.
     */
    public function exportCsv(
        ResponseFactory $responses,
        TaxCsvExporter $exporter,
        CurrentUser $currentUser,
    ): StreamedResponse {
        if (! $currentUser->isAuthenticated()) {
            // Defensive branch — 'auth' middleware blocks unauthenticated access.
            return new StreamedResponse(static function (): void {});
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

    /**
     * Export tagged transactions for the current year as a D-14 A4 PDF.
     * T-07-16: passes the acting user to the user-scoped renderer only.
     */
    public function exportPdf(
        ResponseFactory $responses,
        TaxPdfRenderer $renderer,
        CurrentUser $currentUser,
    ): StreamedResponse {
        if (! $currentUser->isAuthenticated()) {
            return new StreamedResponse(static function (): void {});
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
        // Defence-in-depth: 'auth' middleware makes this unreachable for guests,
        // but guard anyway so an unauthenticated render degrades gracefully (T-07-17).
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

        // Determine if this is a "first visit" (no tax country set).
        // Read from DB (not the model) since tax_country_code is not typed on User.
        // TaxSettingsSection writes this column via DatabaseManager::update().
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
