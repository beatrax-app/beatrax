<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;
use Modules\DriftAlerts\Public\Http\Livewire\SavingsInsightsCard;

// Measured at 390px: the Greek "Δες φθηνότερα προγράμματα" beside a zero-basis
// sentence left the message column 6px of a 306px row, ten lines deep, with the
// text painted under the button. The Dutch label is longer still — 319px of
// button beside a 36px dismiss — so it has to be able to wrap.
function savingsRowFixture(DatabaseManager $db, int $userId): void
{
    $counterpartyId = $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId, 'type' => 'merchant', 'slug' => 'spotify-squeeze',
        'display_name' => 'Spotify', 'merchant_name' => 'Spotify',
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $seriesId = $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId, 'direction' => 'expense', 'detected_name' => 'Spotify',
        'state' => 'approved', 'cadence' => 'monthly', 'latest_amount_minor' => -999,
        'latest_currency' => 'EUR', 'monthly_equivalent_minor' => -999, 'variance_tolerance_percent' => 25,
        'cluster_key' => 'Spotify|monthly|EUR|squeeze',
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN', 'slug' => 'squeeze-asn',
        'kind' => 'bank', 'iban' => 'NL00SQUEEZE00001', 'default_currency' => 'EUR',
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/squeeze.csv',
        'sha256' => str_pad('squeeze', 64, 'a', STR_PAD_LEFT), 'uploaded_at' => '2026-05-01 00:00:00',
        'status' => 'previewed', 'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $transactionId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId,
        'counterparty_id' => $counterpartyId, 'fingerprint' => str_pad('squeeze', 64, 'c', STR_PAD_LEFT),
        'posted_at' => '2026-05-01', 'booked_at' => '2026-05-01 00:00:00', 'value_date' => '2026-05-01',
        'amount_minor' => -999, 'currency' => 'EUR', 'settled_amount_minor' => -999, 'settled_currency' => 'EUR',
        'counterparty_normalized' => 'spotify', 'counterparty_name' => 'SPOTIFY', 'normalization_version' => 1,
        'type' => 'expense', 'source_format' => 'asn-csv', 'source_row_index' => 1,
        'fingerprint_version' => 3, 'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
    $db->connection()->table('recurring_series_occurrences')->insert([
        'user_id' => $userId, 'recurring_series_id' => $seriesId, 'transaction_id' => $transactionId,
        'observed_at' => '2026-05-01', 'observed_amount_minor' => -999, 'observed_currency' => 'EUR',
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
}

/** @return array{row: string, message: string, actions: string} the class lists the rendered row is built from */
function savingsRowClassLists(string $html): array
{
    $row = strpos($html, 'wire:key="insight-');
    expect($row)->not->toBeFalse('The card rendered no insight row.');

    $open = strrpos(substr($html, 0, $row), '<li');
    expect($open)->not->toBeFalse();

    $close = strpos($html, '</li>', $open);
    expect($close)->not->toBeFalse();

    $markup = substr($html, $open, $close - $open);

    $rowMatch = PatternScan::first('/<li\b[^>]*\bclass="([^"]*)"/', $markup);
    $messageMatch = PatternScan::first('/<p\b[^>]*\bclass="([^"]*)"/', $markup);
    $actionsMatch = PatternScan::first('/<div\b[^>]*\bclass="([^"]*)"/', $markup);

    return [
        'row' => $rowMatch[1] ?? '',
        'message' => $messageMatch[1] ?? '',
        'actions' => $actionsMatch[1] ?? '',
    ];
}

// The utility has to resolve to a real length in the stylesheet that ships:
// `basis-64` spelled into a Blade file Tailwind never scanned is a class name
// with no rule behind it, and flex-basis then falls back to flex-1's zero —
// which is the squeeze, passing a class-string assertion on the way through.
function savingsFlexBasisPx(string $utility): float
{
    $built = glob(base_path('public/build/assets/app-*.css')) ?: [];
    expect($built)->not->toBe([], 'No compiled stylesheet under public/build/assets. Run `npm run build`.');

    $css = (string) file_get_contents($built[0]);

    $spacing = PatternScan::first('/--spacing:\s*([0-9.]+)rem/', $css);
    expect($spacing)->not->toBe([]);
    $steps = PatternScan::first('/\.'.preg_quote($utility, '/').'\{flex-basis:calc\(var\(--spacing\) \* ([0-9.]+)\)\}/', $css);
    expect($steps)->not->toBe(
        [],
        $utility.' resolves to no flex-basis rule in the compiled stylesheet.',
    );

    return (float) $spacing[1] * (float) $steps[1] * 16.0;
}

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'savings-squeeze', 'password' => 'fixture-password-12chars', 'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    savingsRowFixture(app(DatabaseManager::class), $this->user->id);
});

it('renders the longest of the 26 action labels whole', function (): void {
    app()->setLocale('nl');

    Livewire::test(SavingsInsightsCard::class)
        ->assertSee('Goedkopere abonnementen bekijken');
});

it('gives the message a column floor the action label cannot take from it', function (): void {
    app()->setLocale('nl');

    $classes = savingsRowClassLists(Livewire::test(SavingsInsightsCard::class)->html());

    expect(str_contains($classes['row'], 'flex-wrap'))->toBeTrue(
        'The insight row does not wrap. A sentence and an action label that cannot both fit are then '
        ."resolved by shrinking the sentence, which is how the Greek message reached 6px of a 306px row.\n  "
        .$classes['row'],
    );

    $basis = PatternScan::first('/\bbasis-([0-9]+)\b/', $classes['message']);

    expect($basis)->not->toBe(
        [],
        "The message column declares no flex-basis, so it takes flex-1's zero and never widens the row it "
        ."is in — the action group beside it decides how much sentence there is.\n  ".$classes['message'],
    );

    expect(savingsFlexBasisPx('basis-'.$basis[1]))->toBeGreaterThanOrEqual(
        192.0,
        'The message column floor is under 192px, which is fewer than thirty characters of a sentence.',
    );
});

it('lets the action group shrink so the longest label wraps instead of overflowing', function (): void {
    app()->setLocale('nl');

    $classes = savingsRowClassLists(Livewire::test(SavingsInsightsCard::class)->html());

    expect(str_contains($classes['actions'], 'shrink-0'))->toBeFalse(
        'The action group holds its max-content width. Measured at 390px, "Goedkopere abonnementen bekijken" '
        .'plus the dismiss is 319px against a 306px row, so the group ran past the card rather than wrapping '
        ."its own label.\n  ".$classes['actions'],
    );
});
