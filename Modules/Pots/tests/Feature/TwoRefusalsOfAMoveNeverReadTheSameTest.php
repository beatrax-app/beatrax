<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\PatternScan;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Pots\Internal\Http\Livewire\PotsPage;
use Modules\Pots\Models\Pot;
use Modules\Pots\Public\Services\PotWriter;

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->euro = trnAccount($this->user->id, 'ASN', 'asn', 'EUR');
    $this->yen = trnAccount($this->user->id, 'Shinsei', 'shinsei', 'JPY');

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/trn.xml',
        'sha256' => str_repeat('t', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->writer = app(PotWriter::class);
});

function trnAccount(int $userId, string $name, string $slug, string $currency): Account
{
    return Account::create([
        'user_id' => $userId,
        'name' => $name,
        'slug' => $slug,
        'kind' => 'bank',
        'iban' => 'NL57ASNB01234567'.substr($slug, 0, 2),
        'default_currency' => $currency,
    ]);
}

function trnCredit(int $userId, int $accountId, int $runId, int $amountMinor, string $currency): void
{
    static $i = 0;
    $i++;

    Transaction::create([
        'user_id' => $userId,
        'account_id' => $accountId,
        'type' => 'transfer_in',
        'posted_at' => CarbonImmutable::now()->toDateString(),
        'booked_at' => CarbonImmutable::now()->toDateString().' 12:00:00',
        'value_date' => CarbonImmutable::now()->toDateString(),
        'amount_minor' => $amountMinor,
        'currency' => $currency,
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => $currency,
        'counterparty_name' => "TRN{$i}",
        'counterparty_normalized' => "trn{$i}",
        'normalization_version' => 1,
        'category_id' => null,
        'source_format' => 'camt053',
        'import_run_id' => $runId,
        'source_row_index' => $i + 9100,
        'fingerprint' => str_pad('trn'.$i, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

function trnPot(int $userId, int $accountId, string $name, string $currency): Pot
{
    /** @var Pot $pot */
    $pot = Pot::query()->withoutGlobalScope(UserScope::class)->create([
        'user_id' => $userId,
        'account_id' => $accountId,
        'goal_id' => null,
        'category_id' => null,
        'name' => $name,
        'currency' => $currency,
        'status' => 'active',
    ]);

    return $pot;
}

// Slot-agnostic on purpose: the fact under test is what the reader is shown,
// not which property carries it, and the defect was two refusals arriving at
// one sentence however they were routed.
function trnRefusalText(string $html): string
{
    $matches = PatternScan::all('/<p\b[^>]*text-rose-600[^>]*>(.*?)<\/p>/s', $html);
    expect(count($matches[0]))->toBeGreaterThan(0, 'the page rendered no refusal at all');

    $seen = [];
    foreach ($matches[1] as $paragraph) {
        $text = trim(html_entity_decode(strip_tags($paragraph), ENT_QUOTES));
        if ($text !== '' && ! in_array($text, $seen, true)) {
            $seen[] = $text;
        }
    }

    return implode(' | ', $seen);
}

function trnRefuseMove(User $user, int $fromPotId, string $targetPotId): string
{
    return trnRefusalText(
        Livewire::actingAs($user)->test(PotsPage::class)
            ->set('operationPotId', $fromPotId)
            ->set('transferTargetPotId', $targetPotId)
            ->set('operationAmount', '10,00')
            ->call('movePot')
            ->html()
    );
}

it('does not answer a cross-account move and a vanished target with one sentence', function (): void {
    trnCredit($this->user->id, $this->euro->id, $this->run->id, 50000, 'EUR');
    $from = trnPot($this->user->id, $this->euro->id, 'From', 'EUR');
    $abroad = trnPot($this->user->id, $this->yen->id, 'Holiday', 'JPY');
    $this->writer->fund($this->user, $from->id, '100,00');

    $crossAccount = trnRefuseMove($this->user, $from->id, (string) $abroad->id);
    $vanishedTarget = trnRefuseMove($this->user, $from->id, '999999');

    expect($crossAccount)->not->toBe($vanishedTarget);
});

it('names the account a cross-currency move could not reach', function (): void {
    trnCredit($this->user->id, $this->euro->id, $this->run->id, 50000, 'EUR');
    $from = trnPot($this->user->id, $this->euro->id, 'From', 'EUR');
    $abroad = trnPot($this->user->id, $this->yen->id, 'Holiday', 'JPY');
    $this->writer->fund($this->user, $from->id, '100,00');

    expect(trnRefuseMove($this->user, $from->id, (string) $abroad->id))
        ->toBe(Lang::get('pots::messages.errors.move_cross_account', [
            'name' => 'Holiday',
            'account' => 'Shinsei',
        ]));
});

it('tells a reader who picked no target that none is picked', function (): void {
    trnCredit($this->user->id, $this->euro->id, $this->run->id, 50000, 'EUR');
    $from = trnPot($this->user->id, $this->euro->id, 'From', 'EUR');
    $this->writer->fund($this->user, $from->id, '100,00');

    expect(trnRefuseMove($this->user, $from->id, ''))
        ->toBe(Lang::get('pots::messages.errors.select_target_pot'));
});

it('tells a reader who aimed a pot at itself which field to change', function (): void {
    trnCredit($this->user->id, $this->euro->id, $this->run->id, 50000, 'EUR');
    $from = trnPot($this->user->id, $this->euro->id, 'From', 'EUR');
    $this->writer->fund($this->user, $from->id, '100,00');

    expect(trnRefuseMove($this->user, $from->id, (string) $from->id))
        ->toBe(Lang::get('pots::messages.errors.move_same_pot'));
});

// A pot id nobody owns and a pot id nobody has must read alike, or the refusal
// answers "does this pot exist?" for another reader's pots.
it('answers a foreign target pot exactly as it answers one that does not exist', function (): void {
    trnCredit($this->user->id, $this->euro->id, $this->run->id, 50000, 'EUR');
    $from = trnPot($this->user->id, $this->euro->id, 'From', 'EUR');
    $this->writer->fund($this->user, $from->id, '100,00');

    $stranger = User::create([
        'username' => 'neighbour',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $theirAccount = trnAccount($stranger->id, 'Rabo', 'rabo', 'EUR');
    $theirPot = trnPot($stranger->id, $theirAccount->id, 'Theirs', 'EUR');

    expect(trnRefuseMove($this->user, $from->id, (string) $theirPot->id))
        ->toBe(trnRefuseMove($this->user, $from->id, '999999'));
});

it('drops the target refusal as soon as another pot is picked', function (): void {
    trnCredit($this->user->id, $this->euro->id, $this->run->id, 50000, 'EUR');
    $from = trnPot($this->user->id, $this->euro->id, 'From', 'EUR');
    $sibling = trnPot($this->user->id, $this->euro->id, 'Sibling', 'EUR');
    $abroad = trnPot($this->user->id, $this->yen->id, 'Holiday', 'JPY');
    $this->writer->fund($this->user, $from->id, '100,00');

    Livewire::actingAs($this->user)->test(PotsPage::class)
        ->set('operationPotId', $from->id)
        ->set('transferTargetPotId', (string) $abroad->id)
        ->set('operationAmount', '10,00')
        ->call('movePot')
        ->assertSet('errorTarget', fn (string $v): bool => $v !== '')
        ->set('transferTargetPotId', (string) $sibling->id)
        ->assertSet('errorTarget', '');
});

// Without .live the pick is ephemeral: it syncs to the client-side proxy and
// sends no request, so the hook that clears the refusal does not run and the
// message stands over a pot the reader has already changed.
it('syncs the move target before the next submit', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Pots/Resources/views/livewire/pots-page.blade.php')
    );

    expect(substr_count($blade, 'wire:model.live="transferTargetPotId"'))->toBe(2);
});
