<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\RenderedMarkup;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyIndex;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyProfile;
use Modules\Ledger\Public\Enums\Currency;

// `abs()` takes the sign off the twelve-month total before any surface sees it,
// so the word beside the figure is the entire claim and nothing on the screen
// can contradict it. The card and the profile hero picked that word by the
// counterparty's TYPE rather than by the sign, so every personal row read "Net
// received": a friend you had net paid €360 was told you had received it.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-23 09:00:00');
    $this->db = app(DatabaseManager::class);
    $this->user = User::query()->create([
        'username' => 'cp-net-label-fixture',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

/**
 * A counterparty whose twelve-month total is the sum of $minors — several
 * movements where the figure being a net of them is the point.
 */
function cpNetLabelParty(DatabaseManager $db, int $userId, string $slug, string $name, string $type, int ...$minors): void
{
    $hex = bin2hex(random_bytes(4));

    $cpId = $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId, 'slug' => $slug, 'display_name' => $name,
        'merchant_name' => $type === 'merchant' ? $name : null, 'type' => $type,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);

    if ($minors === []) {
        return;
    }

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

    foreach ($minors as $index => $minor) {
        $db->connection()->table('transactions')->insert([
            'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId,
            'counterparty_id' => $cpId, 'category_id' => null,
            'fingerprint' => hash('sha256', $hex.'-'.$index), 'fingerprint_version' => 3,
            'posted_at' => '2026-08-01', 'booked_at' => '2026-08-01 12:00:00', 'value_date' => '2026-08-01',
            'amount_minor' => $minor, 'currency' => Currency::Eur->value,
            'settled_amount_minor' => $minor, 'settled_currency' => Currency::Eur->value,
            'counterparty_normalized' => 'party', 'counterparty_name' => $name,
            'normalization_version' => 1, 'description' => 'fixture',
            'type' => $minor < 0 ? 'expense' : 'income',
            'source_format' => 'revolut-csv', 'source_row_index' => $index + 1,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
    }
}

/**
 * @return array<string, string> the label under each card's twelve-month total, keyed by the name on the card
 */
function cpNetLabelCardLabels(string $html): array
{
    $labels = [];

    foreach (RenderedMarkup::of($html)->all('.cp-card') as $card) {
        $labels[$card->firstOrFail('.cp-head-name')->text()] = $card->firstOrFail('.cp-stat')->firstOrFail('.label')->text();
    }

    return $labels;
}

function cpNetLabelCards(User $user): string
{
    $component = Livewire::actingAs($user)->test(CounterpartyIndex::class);
    $component->call('setView', 'cards');

    return (string) $component->html();
}

function cpNetLabelHero(User $user, string $slug): string
{
    $html = (string) Livewire::actingAs($user)->test(CounterpartyProfile::class, ['slug' => $slug])->html();

    return RenderedMarkup::of($html)->firstOrFail('.cp-profile-hero-stats .frame-tight')->text();
}

it('does not tell a card that money went out that it came in', function (): void {
    cpNetLabelParty($this->db, $this->user->id, 'landlord', 'Landlord', 'personal', -36_000);

    $labels = cpNetLabelCardLabels(cpNetLabelCards($this->user));

    expect($labels['Landlord'])->toBe(Lang::get('core::dashboard.out'))
        ->and($labels['Landlord'])->not->toContain(Lang::get('core::dashboard.in'));
});

// The defect was that the label was chosen by the type, so the two rows below
// read the same words over opposite figures. Different types would prove
// nothing: only two rows of ONE type can tell a label that follows the sign
// from one that follows the type.
it('gives two personal counterparties the opposite words for opposite totals', function (): void {
    cpNetLabelParty($this->db, $this->user->id, 'landlord', 'Landlord', 'personal', -36_000);
    cpNetLabelParty($this->db, $this->user->id, 'payroll', 'Payroll BV', 'personal', 36_000);

    $labels = cpNetLabelCardLabels(cpNetLabelCards($this->user));

    expect($labels['Payroll BV'])->toBe(Lang::get('core::dashboard.in'))
        ->and($labels['Landlord'])->toBe(Lang::get('core::dashboard.out'))
        ->and($labels['Landlord'])->not->toBe($labels['Payroll BV']);
});

// Absence has to mean "no direction" rather than "the other direction", which
// is the rule the phone list already keeps.
it('leaves a card whose total nets to nothing pointing neither way', function (): void {
    cpNetLabelParty($this->db, $this->user->id, 'settled-up', 'Settled Up', 'personal', 36_000, -36_000);

    $labels = cpNetLabelCardLabels(cpNetLabelCards($this->user));

    expect($labels['Settled Up'])->toBe(Lang::get('counterparties::index.stat_12mo'))
        ->and($labels['Settled Up'])->not->toContain(Lang::get('core::dashboard.in'))
        ->and($labels['Settled Up'])->not->toContain(Lang::get('core::dashboard.out'));
});

it('does not tell a profile hero that money went out that it came in', function (): void {
    cpNetLabelParty($this->db, $this->user->id, 'landlord', 'Landlord', 'personal', -36_000);

    $hero = cpNetLabelHero($this->user, 'landlord');

    expect($hero)->toStartWith(Lang::get('core::dashboard.out'))
        ->and($hero)->not->toContain(Lang::get('core::dashboard.in'));
});

it('gives a profile hero the opposite word for the opposite total', function (): void {
    cpNetLabelParty($this->db, $this->user->id, 'payroll', 'Payroll BV', 'personal', 36_000);

    expect(cpNetLabelHero($this->user, 'payroll'))->toStartWith(Lang::get('core::dashboard.in'));
});

it('leaves a profile hero that nets to nothing on the name of the period', function (): void {
    cpNetLabelParty($this->db, $this->user->id, 'settled-up', 'Settled Up', 'personal', 36_000, -36_000);

    $hero = cpNetLabelHero($this->user, 'settled-up');

    expect($hero)->toStartWith(Lang::get('counterparties::profile.hero_12mo_total'))
        ->and($hero)->not->toContain(Lang::get('core::dashboard.out'));
});

// Only a personal counterparty ever reported a direction here, and that half is
// unchanged: a merchant total is a magnitude under the name of the period.
it('leaves a merchant card and hero naming the period and not a direction', function (): void {
    cpNetLabelParty($this->db, $this->user->id, 'grocer', 'Grocer BV', 'merchant', -36_000);

    $labels = cpNetLabelCardLabels(cpNetLabelCards($this->user));

    expect($labels['Grocer BV'])->toBe(Lang::get('counterparties::index.stat_12mo'))
        ->and(cpNetLabelHero($this->user, 'grocer'))->toStartWith(Lang::get('counterparties::profile.hero_12mo_total'));
});
