<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Chains\Internal\ChainLinkInsertHelper;
use Modules\Chains\Internal\Http\Livewire\ChainHintsQueue;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

uses(RefreshDatabase::class);

// A chain link id is minted by DerivedRowId so both devices agree on it, which
// puts it past 2^53. The queue used to hand that id to the browser as a number
// literal; JS numbers are doubles, so the value came back rounded and Dismiss
// matched no row. On a paired iPhone the hint survived every tap and reload.

// The tests below drive the same method the wire does — once with the id as the
// string it now travels as, and once with the rounded number it used to become.

function browserTripUser(): User
{
    return User::query()->create([
        'username' => 'browser-trip-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

/** @return array{0: User, 1: int} the owner and the id of its one hint */
function browserTripHint(): array
{
    $user = browserTripUser();

    $card = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'Browser trip card',
        'slug' => 'browser-trip-'.bin2hex(random_bytes(3)),
        'kind' => 'ics_card',
        'iban' => 'ICS-BROWSER-TRIP-'.$user->id,
        'default_currency' => 'EUR',
    ]);
    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/browser-trip.pdf',
        'sha256' => hash('sha256', 'browser-trip-'.$user->id),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
    $charge = Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $card->id,
        'type' => 'expense',
        'posted_at' => '2026-05-12',
        'booked_at' => '2026-05-12 12:00:00',
        'value_date' => '2026-05-12',
        'amount_minor' => -2599,
        'currency' => 'EUR',
        'settled_amount_minor' => -2599,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Coolblue',
        'counterparty_normalized' => 'coolblue',
        'normalization_version' => 3,
        'source_format' => 'ics-pdf',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'browser-trip-tx-'.$user->id),
        'fingerprint_version' => 3,
    ]);

    $id = ChainLinkInsertHelper::idFor($user->id, (int) $charge->id, null, 'funded_by_card_hint');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('chain_links')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'from_transaction_id' => $charge->id,
        'to_transaction_id' => null,
        'kind' => 'funded_by_card_hint',
        'state' => 'candidate',
        'confidence' => '0.500',
        'resolver' => 'auto',
        'evidence' => json_encode(['card_last4' => '4321', 'source_evidence' => []]),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    return [$user, $id];
}

function browserTripHintExists(int $id): bool
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('chain_links')->where('id', $id)->exists();
}

it('mints a hint id the browser cannot hold as a number', function (): void {
    [, $id] = browserTripHint();

    expect($id)->toBeGreaterThan(9007199254740991)
        ->and((int) (float) $id)->not->toBe($id);
});

it('dismisses the hint when the id arrives as the string the wire now sends', function (): void {
    [$user, $id] = browserTripHint();
    $this->actingAs($user);

    Livewire::test(ChainHintsQueue::class)
        ->call('dismiss', (string) $id)
        ->assertStatus(200)
        ->assertSet('statusMessage', Lang::get('chains::hints.dismissed'));

    expect(browserTripHintExists($id))->toBeFalse();
});

// The failure this fix exists for, run rather than described: the id the old
// blade produced is the one a double can hold, and it names no row.
it('leaves the hint standing when the id arrives rounded, as a number literal did', function (): void {
    [$user, $id] = browserTripHint();
    $this->actingAs($user);

    Livewire::test(ChainHintsQueue::class)
        ->call('dismiss', (int) (float) $id)
        ->assertStatus(200)
        ->assertSet('statusMessage', Lang::get('core::errors.no_longer_here'));

    expect(browserTripHintExists($id))->toBeTrue();
});

it('writes the id into the page quoted, so the browser never parses it as a number', function (): void {
    [$user, $id] = browserTripHint();
    $this->actingAs($user);

    Livewire::test(ChainHintsQueue::class)
        ->assertSee("dismiss('".$id."')", false)
        ->assertDontSee('dismiss('.$id.')', false);
});
