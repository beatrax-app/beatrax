<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Navigation\Destination;

uses(RefreshDatabase::class);

// Unusual charges and Drift alerts are one route told apart by a query
// parameter. The rail compared paths, so on the anomaly screen it lit the
// Drift row — the one the reader had not opened — and left theirs unlit.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'rail-active-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($this->user);
});

/** @return array{unusual: bool, drift: bool} */
function railActiveRows(string $html): array
{
    $row = static function (Destination $destination) use ($html): bool {
        $href = htmlspecialchars($destination->url(), ENT_QUOTES);
        $needle = '<a href="'.$href.'" class="side-item ';

        $at = strpos($html, $needle);
        if ($at === false) {
            return false;
        }

        return str_starts_with(substr($html, $at + strlen($needle)), 'active');
    };

    return [
        'unusual' => $row(Destination::UnusualCharges),
        'drift' => $row(Destination::DriftAlerts),
    ];
}

it('lights Unusual charges, and only that row, on the anomaly screen', function (): void {
    $html = $this->get(Destination::UnusualCharges->url())->assertOk()->getContent();

    expect(railActiveRows((string) $html))->toBe(['unusual' => true, 'drift' => false]);
});

it('lights Drift alerts, and only that row, on the drift screen', function (): void {
    $html = $this->get(Destination::DriftAlerts->url())->assertOk()->getContent();

    expect(railActiveRows((string) $html))->toBe(['unusual' => false, 'drift' => true]);
});

// A sort or a page in the address is not a different screen, so a row still
// lights with a parameter its destination never declared.
it('still lights the row the reader opened when the address carries more', function (): void {
    $html = $this->get(Destination::DriftAlerts->url(['page' => '2']))->assertOk()->getContent();

    expect(railActiveRows((string) $html)['drift'])->toBeTrue();
});
