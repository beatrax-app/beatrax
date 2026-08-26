<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Tests\Helpers\CssRule;

// Four of the dashboard's twelve children measured height 0 on a fresh
// install: tiles that had nothing to show, each still holding the root element
// Livewire requires. A zero-height flex child costs a full gap on each side of
// itself, and three of the four sat together directly under the header --
// 96px of blank space between it and the first figure, on the screen the app
// opens to.

function quietDashboardUser(): User
{
    return User::query()->create([
        'username' => 'quiet-dashboard-fixture',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// A tile with nothing to show renders its root and nothing inside it, which is
// exactly what the selector keys on. Counting elements rather than matching
// text: a comment or whitespace inside the root is fine, an element is not.
function elementsRenderedBy(string $html): int
{
    $document = new DOMDocument;
    $loaded = @$document->loadHTML('<?xml encoding="UTF-8"?><body>'.$html.'</body>', LIBXML_NOWARNING | LIBXML_NOERROR);
    expect($loaded)->toBeTrue();

    $body = $document->getElementsByTagName('body')->item(0);
    expect($body)->not->toBeNull();

    return $body->getElementsByTagName('*')->length;
}

it('hides a dashboard tile whose content did not render', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    expect(CssRule::blockFor($css, '.dashboard-main > .dashboard-tile'))->toContain('display: none;');
});

it('marks every tile that documents an empty state', function (): void {
    $blade = (string) file_get_contents(base_path('Modules/Shell/Resources/views/livewire/dashboard.blade.php'));

    $unmarked = [];
    foreach (['drift-alerts.dashboard-drift-badge', 'anomaly.dashboard-anomaly-badge', 'goals.summary-card',
        'tax.summary-card', 'budgets.envelope-glance-card', 'reports.pinned-reports-row'] as $tile) {
        $at = strpos($blade, $tile);
        expect($at)->not->toBeFalse($tile.' is no longer on the dashboard.');

        $opening = strrpos(substr($blade, 0, (int) $at), '<div class=');
        if (! str_contains(substr($blade, (int) $opening, (int) $at - (int) $opening), 'dashboard-tile')) {
            $unmarked[] = $tile;
        }
    }

    expect($unmarked)->toBe([], 'These tiles can render nothing and still hold a gap: '.implode(', ', $unmarked));
});

it('leaves nothing but its root element behind when a tile is quiet', function (): void {
    $this->actingAs(quietDashboardUser());

    // No alerts, no anomalies, no goals, no pins, no tagged tax rows: the
    // fresh-install state the walk measured.
    expect(DB::table('drift_alerts')->count())->toBe(0);

    foreach (['drift-alerts.dashboard-drift-badge', 'anomaly.dashboard-anomaly-badge', 'reports.pinned-reports-row'] as $tile) {
        expect(elementsRenderedBy(Livewire::test($tile)->html()))
            ->toBe(1, $tile.' renders an element inside its root with nothing to show, so the tile keeps its gap.');
    }
});

it('gives the install hint its own order class rather than a wrapper that outlives it', function (): void {
    $blade = (string) file_get_contents(base_path('Modules/Shell/Resources/views/livewire/dashboard.blade.php'));

    expect($blade)->toContain('<x-core::install-hint class="dashboard-phone-order-8" />');

    $component = (string) file_get_contents(base_path('Modules/Core/Resources/views/components/install-hint.blade.php'));
    $root = substr($component, (int) strpos($component, '<div'), (int) strpos($component, '<aside'));

    expect($root)->toContain('{{ $attributes }}')
        ->and($root)->toContain('x-show="shown"');
});
