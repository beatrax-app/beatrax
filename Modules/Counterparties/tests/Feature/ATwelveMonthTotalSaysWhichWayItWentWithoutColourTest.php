<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\RenderedMarkup;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyIndex;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\ValueObjects\Money;

// The twelve-month total is drawn as a magnitude on purpose, so the only thing
// separating money received from money paid on the phone list was an emerald
// inline style. Read aloud, or read by anyone who cannot tell the two colours
// apart, an employer and a landlord printed the same figure.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-23 09:00:00');
    $this->db = app(DatabaseManager::class);
    $this->user = User::query()->create([
        'username' => 'cp-direction-fixture',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function cpDirectionParty(DatabaseManager $db, int $userId, string $slug, string $name, string $type): int
{
    return $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId, 'slug' => $slug, 'display_name' => $name,
        'merchant_name' => $type === 'merchant' ? $name : null, 'type' => $type,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function cpDirectionMovement(DatabaseManager $db, int $userId, int $cpId, int $minor): void
{
    $hex = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'Bank '.$hex, 'slug' => 'bank-'.$hex, 'kind' => 'bank',
        'iban' => 'GB00BANK'.strtoupper($hex), 'default_currency' => Currency::Eur->value,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'revolut-csv', 'raw_file_path' => '/tmp/run-'.$hex.'.csv',
        'sha256' => hash('sha256', 'run-'.$hex), 'uploaded_at' => '2026-01-01 00:00:00', 'status' => 'committed',
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $db->connection()->table('transactions')->insert([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId,
        'counterparty_id' => $cpId, 'category_id' => null,
        'fingerprint' => hash('sha256', 'fp-'.$hex), 'fingerprint_version' => 3,
        'posted_at' => '2026-08-01', 'booked_at' => '2026-08-01 12:00:00', 'value_date' => '2026-08-01',
        'amount_minor' => $minor, 'currency' => Currency::Eur->value,
        'settled_amount_minor' => $minor, 'settled_currency' => Currency::Eur->value,
        'counterparty_normalized' => 'party', 'counterparty_name' => 'Party',
        'normalization_version' => 1, 'description' => 'fixture',
        'type' => $minor < 0 ? 'expense' : 'income',
        'source_format' => 'revolut-csv', 'source_row_index' => 1,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
}

/**
 * @return array<string, string> the reader text of each phone-list row, keyed by which way its total went
 */
function cpDirectionReadings(string $html): array
{
    $readings = [];

    foreach (RenderedMarkup::of($html)->all('.card-list-item') as $row) {
        $text = $row->text();
        $readings[str_contains($text, 'Payroll') ? 'received' : 'paid'] = $text;
    }

    return $readings;
}

it('tells a total that came in from one that went out with no colour in the reading', function (): void {
    cpDirectionMovement($this->db, $this->user->id, cpDirectionParty($this->db, $this->user->id, 'payroll', 'Payroll BV', 'personal'), 36_000);
    cpDirectionMovement($this->db, $this->user->id, cpDirectionParty($this->db, $this->user->id, 'grocer', 'Grocer BV', 'merchant'), -36_000);

    $component = Livewire::actingAs($this->user)->test(CounterpartyIndex::class);
    $component->call('setView', 'list');

    $readings = cpDirectionReadings((string) $component->html());
    $figure = Money::ofMinor(36_000, Currency::Eur->value)->format();

    expect($readings)->toHaveKeys(['received', 'paid']);

    // The figure stays the magnitude it is drawn as everywhere else, so it is
    // not the thing telling the two rows apart.
    expect(substr_count($readings['received'], $figure))->toBe(1)
        ->and(substr_count($readings['paid'], $figure))->toBe(1);

    // A reading carries no attributes at all, so what separates these two
    // strings is what survives when colour does not.
    expect($readings['received'])->toEndWith(Lang::get('core::dashboard.in'))
        ->and($readings['paid'])->toEndWith(Lang::get('core::dashboard.out'))
        ->and($readings['paid'])->not->toContain(Lang::get('core::dashboard.in'));
});

it('leaves a total of nothing pointing neither way', function (): void {
    cpDirectionParty($this->db, $this->user->id, 'dormant', 'Dormant BV', 'merchant');

    $component = Livewire::actingAs($this->user)->test(CounterpartyIndex::class);
    $component->call('setView', 'list');

    $readings = cpDirectionReadings((string) $component->html());

    expect($readings['paid'])->not->toContain(Lang::get('core::dashboard.in'))
        ->and($readings['paid'])->not->toContain(Lang::get('core::dashboard.out'));
});
