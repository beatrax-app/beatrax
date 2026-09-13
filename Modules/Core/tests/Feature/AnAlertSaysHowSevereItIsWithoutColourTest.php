<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\SystemAlertSeverity;
use Modules\Core\Public\Http\Livewire\SystemAlertsBanner;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\RenderedMarkup;

// The row body is written the same way whatever the severity is — it says so
// itself — so rose against amber was the whole of the difference between an
// alert about unredacted tokens and one about a backup a day late. A reader who
// hears the banner, and a reader who cannot separate the two hues, were handed
// three tiers of urgency as one.

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->reader = User::query()->create([
        'username' => 'severity-word-reader',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
});

/** A kind the banner has no template for, so the row prints its own message and nothing else varies. */
function severityWordAlert(DatabaseManager $db, int $userId, string $severity, string $message): void
{
    $db->connection()->table('system_alerts')->insert([
        'user_id' => $userId,
        'kind' => 'fixture.severity.'.$severity,
        'severity' => $severity,
        'message' => $message,
        'metadata' => null,
        'created_at' => '2026-05-20 01:00:00',
        'acknowledged_at' => null,
    ]);
}

/**
 * The reading of each banner row, keyed by the message it carries. A reading
 * carries no attributes at all, so a tone class cannot reach the assertion.
 *
 * @return array<string, string>
 */
function severityWordReadings(string $html): array
{
    $readings = [];

    foreach (RenderedMarkup::of($html)->all('section > div') as $row) {
        $text = $row->text();

        foreach (['crit', 'warn', 'note'] as $marker) {
            if (str_contains($text, $marker)) {
                $readings[$marker] = $text;
            }
        }
    }

    return $readings;
}

it('names the severity of a row in a word the reading carries', function (): void {
    severityWordAlert($this->db, $this->reader->id, SystemAlertSeverity::Critical->value, 'crit');
    severityWordAlert($this->db, $this->reader->id, SystemAlertSeverity::Warning->value, 'warn');

    $component = Livewire::actingAs($this->reader)->test(SystemAlertsBanner::class);
    $readings = severityWordReadings((string) $component->html());

    $critical = Lang::get('core::alerts.severity.critical');
    $warning = Lang::get('core::alerts.severity.warning');

    expect($readings)->toHaveKeys(['crit', 'warn']);

    expect($readings['crit'])->toContain($critical)
        ->and($readings['crit'])->not->toContain($warning)
        ->and($readings['warn'])->toContain($warning)
        ->and($readings['warn'])->not->toContain($critical);
});

it('leaves an informational row naming no severity at all', function (): void {
    severityWordAlert($this->db, $this->reader->id, SystemAlertSeverity::Info->value, 'note');

    $component = Livewire::actingAs($this->reader)->test(SystemAlertsBanner::class);
    $readings = severityWordReadings((string) $component->html());

    expect($readings)->toHaveKey('note');

    // Absence has to mean "nothing is wrong here". A word on the quiet tier
    // would make the silence on the loud one ambiguous instead.
    expect($readings['note'])->not->toContain(Lang::get('core::alerts.severity.critical'))
        ->and($readings['note'])->not->toContain(Lang::get('core::alerts.severity.warning'));
});
