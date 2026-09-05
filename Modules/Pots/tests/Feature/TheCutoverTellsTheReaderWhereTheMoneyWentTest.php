<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\Budgets\Public\Services\EnvelopeActivationService;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\PotAlertKind;
use Modules\Core\Public\Enums\SystemAlertSeverity;
use Modules\Core\Public\Http\Livewire\SystemAlertsBanner;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Pots\Models\Pot;
use Modules\Pots\Public\Actions\RecordCategoryPotRetirementAlert;
use Modules\Pots\Public\Enums\PotMovementKind;
use Modules\Sync\Public\Events\EntityMutated;

uses(RefreshDatabase::class);

// The cutover releases money the reader had set aside, on a device they only
// opened to install an update. Nothing they did caused it, so the app has to
// say so — the release body reaches whoever goes looking for one, and the
// desktop updater renders none of its own.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'cutover-notice-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    $this->groceries = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'notice-groceries-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);
});

function noticeAccount(mixed $user, string $currency): Account
{
    return Account::create([
        'user_id' => $user->id,
        'name' => 'ASN '.$currency,
        'slug' => 'notice-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => $currency,
    ]);
}

function fundedCategoryPot(mixed $user, Account $account, ?int $categoryId, int $minor): Pot
{
    /** @var Pot $pot */
    $pot = Pot::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $categoryId,
        'goal_id' => null,
        'name' => 'Pot '.bin2hex(random_bytes(3)),
        'currency' => $account->default_currency,
        'status' => 'active',
    ]);

    if ($minor > 0) {
        DB::table('pot_movements')->insert([
            'user_id' => $user->id,
            'pot_id' => $pot->id,
            'counterpart_pot_id' => null,
            'amount_minor' => $minor,
            'currency' => $account->default_currency,
            'kind' => PotMovementKind::Fund->value,
            'memo' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $pot;
}

/** @return list<object> */
function retirementAlerts(mixed $user): array
{
    return DB::table('system_alerts')
        ->where('user_id', $user->id)
        ->where('kind', PotAlertKind::CategoryLinkRetired->value)
        ->orderBy('id')
        ->get()
        ->all();
}

it('names the money the cutover released and the pots it came out of', function (): void {
    $account = noticeAccount($this->user, 'EUR');
    fundedCategoryPot($this->user, $account, $this->groceries->id, 15000);
    fundedCategoryPot($this->user, $account, $this->groceries->id, 5000);

    app(EnvelopeActivationService::class)->activate();

    $alerts = retirementAlerts($this->user);
    expect($alerts)->toHaveCount(1);

    $row = $alerts[0];
    expect($row->severity)->toBe(SystemAlertSeverity::Warning->value);

    /** @var array<string, mixed> $metadata */
    $metadata = json_decode((string) $row->metadata, true, flags: JSON_THROW_ON_ERROR);
    $spec = $metadata['copy'];

    expect($spec['count'])->toBe(2)
        ->and($spec['replace']['amount']['value'])->toBe('20000|EUR');

    Livewire::actingAs($this->user)->test(SystemAlertsBanner::class)
        ->assertSee(Money::ofMinor(20000, 'EUR')->format())
        ->assertSee('Envelope budgeting has replaced category-linked pots');
});

it('gives each currency its own line, because two currencies have no sum', function (): void {
    fundedCategoryPot($this->user, noticeAccount($this->user, 'EUR'), $this->groceries->id, 15000);
    fundedCategoryPot($this->user, noticeAccount($this->user, 'SEK'), $this->groceries->id, 40000);

    app(EnvelopeActivationService::class)->activate();

    $alerts = retirementAlerts($this->user);
    expect($alerts)->toHaveCount(2);

    Livewire::actingAs($this->user)->test(SystemAlertsBanner::class)
        ->assertSee(Money::ofMinor(15000, 'EUR')->format())
        ->assertSee(Money::ofMinor(40000, 'SEK')->format());
});

it('says nothing when no money moved', function (): void {
    $account = noticeAccount($this->user, 'EUR');
    fundedCategoryPot($this->user, $account, $this->groceries->id, 0);
    fundedCategoryPot($this->user, $account, null, 9000);

    app(EnvelopeActivationService::class)->activate();

    expect(retirementAlerts($this->user))->toBe([]);
});

it('derives one row, so the second device to run the cutover does not raise a second banner', function (): void {
    $account = noticeAccount($this->user, 'EUR');
    fundedCategoryPot($this->user, $account, $this->groceries->id, 15000);

    app(EnvelopeActivationService::class)->activate();
    $first = retirementAlerts($this->user);

    // The peer walks the same rows and computes the same figures, which is the
    // whole point of deriving the id rather than minting one.
    app(RecordCategoryPotRetirementAlert::class)($this->user->id);

    $second = retirementAlerts($this->user);
    expect($second)->toHaveCount(1)
        ->and($second[0]->id)->toBe($first[0]->id);
});

it('reads in the reader language, not the one the migration ran in', function (): void {
    $account = noticeAccount($this->user, 'EUR');
    fundedCategoryPot($this->user, $account, $this->groceries->id, 15000);

    app(EnvelopeActivationService::class)->activate();

    app()->setLocale('nl');

    Livewire::actingAs($this->user)->test(SystemAlertsBanner::class)
        ->assertDontSee('Envelope budgeting has replaced category-linked pots');
});

it('goes away when the reader dismisses it, and stays away', function (): void {
    $account = noticeAccount($this->user, 'EUR');
    fundedCategoryPot($this->user, $account, $this->groceries->id, 15000);

    app(EnvelopeActivationService::class)->activate();
    $alertId = retirementAlerts($this->user)[0]->id;

    Livewire::actingAs($this->user)->test(SystemAlertsBanner::class)
        ->call('acknowledge', (string) $alertId)
        ->assertDontSee('Envelope budgeting has replaced category-linked pots');

    expect(DB::table('system_alerts')->where('id', $alertId)->value('acknowledged_at'))->not->toBeNull();
});

it('announces the row once, so a peer that derived the same id is not told twice', function (): void {
    $account = noticeAccount($this->user, 'EUR');
    fundedCategoryPot($this->user, $account, $this->groceries->id, 15000);

    Event::fake([EntityMutated::class]);

    app(EnvelopeActivationService::class)->activate();
    app(RecordCategoryPotRetirementAlert::class)($this->user->id);

    $announced = 0;
    Event::assertDispatched(EntityMutated::class, function (EntityMutated $event) use (&$announced): bool {
        if ($event->table === 'system_alerts') {
            $announced++;
        }

        return true;
    });

    expect($announced)->toBe(1);
});

it('still tells a device whose peer did the archiving, because it reads the rows and not the walk', function (): void {
    $account = noticeAccount($this->user, 'EUR');
    $pot = fundedCategoryPot($this->user, $account, $this->groceries->id, 15000);

    // What a phone holds after the desktop upgraded and the backfill landed:
    // the pot already archived, the release movement already written, and no
    // active category-linked pot left for its own walk to find.
    DB::table('pots')->where('id', $pot->id)->update(['status' => 'archived']);
    DB::table('pot_movements')->insert([
        'user_id' => $this->user->id,
        'pot_id' => $pot->id,
        'counterpart_pot_id' => null,
        'amount_minor' => -15000,
        'currency' => 'EUR',
        'kind' => PotMovementKind::ReleasedOnArchive->value,
        'memo' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(EnvelopeActivationService::class)->activate();

    expect(retirementAlerts($this->user))->toHaveCount(1);

    Livewire::actingAs($this->user)->test(SystemAlertsBanner::class)
        ->assertSee(Money::ofMinor(15000, 'EUR')->format());
});
