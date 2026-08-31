<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Goals\Internal\Http\Livewire\GoalsPage;
use Modules\Goals\Models\Goal;
use Modules\Goals\Public\Services\GoalContributionWriter;
use Modules\Goals\Public\Services\GoalProgressQuery;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Pots\Models\Pot;

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);
});

it('renders the goals page', function (): void {
    Livewire::test(GoalsPage::class)
        ->assertOk()
        ->assertSee('Goals');
});

it('creates a goal, writes a goals row, and dispatches a toast', function (): void {
    Livewire::test(GoalsPage::class)
        ->set('name', 'Emergency fund')
        ->set('targetAmount', '1000.00')
        ->set('targetDate', '2027-01-01')
        ->call('createGoal')
        ->assertDispatched('toast');

    $this->assertDatabaseHas('goals', [
        'user_id' => $this->user->id,
        'name' => 'Emergency fund',
        'target_minor' => 100000,
        'target_currency' => 'EUR',
        'status' => 'active',
    ]);
});

it('rejects an invalid amount without writing a goal row', function (): void {
    Livewire::test(GoalsPage::class)
        ->set('name', 'Bad goal')
        ->set('targetAmount', 'not-a-number')
        ->set('targetDate', '2027-01-01')
        ->call('createGoal');

    $this->assertDatabaseMissing('goals', [
        'name' => 'Bad goal',
    ]);
});

it('rejects a zero or negative amount', function (): void {
    Livewire::test(GoalsPage::class)
        ->set('name', 'Zero goal')
        ->set('targetAmount', '0')
        ->set('targetDate', '2027-01-01')
        ->call('createGoal');

    $this->assertDatabaseMissing('goals', ['name' => 'Zero goal']);
});

it('parses the Dutch grouped amount format', function (): void {
    Livewire::test(GoalsPage::class)
        ->set('name', 'Dutch amount goal')
        ->set('targetAmount', '1.234,56')
        ->set('targetDate', '2027-01-01')
        ->call('createGoal');

    $this->assertDatabaseHas('goals', [
        'name' => 'Dutch amount goal',
        'target_minor' => 123456,
    ]);
});

it('updates an existing goals name and target via edit', function (): void {
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Old name',
        'target_minor' => 50000,
        'status' => 'active',
    ]);

    Livewire::test(GoalsPage::class, ['editGoalId' => $goal->id])
        ->set('name', 'New name')
        ->set('targetAmount', '750.00')
        ->set('targetDate', '2027-06-01')
        ->call('updateGoal')
        ->assertDispatched('toast');

    $this->assertDatabaseHas('goals', [
        'id' => $goal->id,
        'name' => 'New name',
        'target_minor' => 75000,
    ]);
});

it('openEdit prefills the target date so the edit can be saved without re-entry', function (): void {
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Old name',
        'target_minor' => 50000,
        'target_date' => '2027-06-01',
        'status' => 'active',
    ]);

    // openEdit must populate targetDate from the stored goal; blanking it made
    // the very next save fail date validation.
    Livewire::test(GoalsPage::class)
        ->call('openEdit', $goal->id)
        ->assertSet('editGoalId', $goal->id)
        ->assertSet('name', 'Old name')
        ->assertSet('targetDate', '2027-06-01')
        ->set('name', 'New name')
        ->set('targetAmount', '750.00')
        ->call('updateGoal')
        ->assertDispatched('toast')
        ->assertSet('errorDate', '');

    $this->assertDatabaseHas('goals', [
        'id' => $goal->id,
        'name' => 'New name',
        'target_minor' => 75000,
    ]);
});

it('markComplete sets status to completed and the goal remains in the list', function (): void {
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);

    Livewire::test(GoalsPage::class)
        ->call('markComplete', $goal->id)
        ->assertDispatched('toast');

    $this->assertDatabaseHas('goals', [
        'id' => $goal->id,
        'status' => 'completed',
    ]);
});

it('archive sets status to archived and hides goal from active view', function (): void {
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);

    Livewire::test(GoalsPage::class)
        ->call('archive', $goal->id)
        ->assertDispatched('toast');

    $this->assertDatabaseHas('goals', [
        'id' => $goal->id,
        'status' => 'archived',
    ]);
});

it('restore returns an archived goal to active status', function (): void {
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'status' => 'archived',
    ]);

    Livewire::test(GoalsPage::class)
        ->call('restore', $goal->id)
        ->assertDispatched('toast');

    $this->assertDatabaseHas('goals', [
        'id' => $goal->id,
        'status' => 'active',
    ]);
});

it('cross-user cannot edit another users goal', function (): void {
    $other = User::create([
        'username' => 'mallory',
        'password' => 'x',
        'period_start_day' => 1,
    ]);
    $foreignGoal = Goal::factory()->create([
        'user_id' => $other->id,
        'name' => 'Mallory goal',
        'target_minor' => 100000,
        'status' => 'active',
    ]);

    Livewire::test(GoalsPage::class, ['editGoalId' => $foreignGoal->id])
        ->set('name', 'Hacked')
        ->set('targetAmount', '1')
        ->call('updateGoal');

    $this->assertDatabaseMissing('goals', [
        'id' => $foreignGoal->id,
        'name' => 'Hacked',
    ]);
});

it('shows a name error and writes nothing when the name is blank', function (): void {
    Livewire::test(GoalsPage::class)
        ->set('name', '')
        ->set('targetAmount', '1000.00')
        ->set('targetDate', '2027-01-01')
        ->call('createGoal')
        ->assertSet('errorName', 'Enter a name for your goal.')
        ->assertNotDispatched('toast');

    $this->assertDatabaseCount('goals', 0);
});

it('shows a date error when the target date is blank', function (): void {
    Livewire::test(GoalsPage::class)
        ->set('name', 'Dated goal')
        ->set('targetAmount', '1000.00')
        ->set('targetDate', '')
        ->call('createGoal')
        ->assertSet('errorDate', 'Choose a target date.')
        ->assertNotDispatched('toast');

    $this->assertDatabaseMissing('goals', ['name' => 'Dated goal']);
});

it('surfaces a linked-pot error when the goal write rejects the pot', function (): void {
    $other = User::create([
        'username' => 'mallory-pot',
        'password' => 'x',
        'period_start_day' => 1,
    ]);
    $foreignAccount = Account::create([
        'user_id' => $other->id,
        'name' => 'Mallory ASN',
        'slug' => 'mallory-asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB1111111111',
        'default_currency' => 'EUR',
    ]);
    $foreignPot = Pot::factory()->create([
        'user_id' => $other->id,
        'account_id' => $foreignAccount->id,
        'goal_id' => null,
        'category_id' => null,
    ]);

    Livewire::test(GoalsPage::class)
        ->set('name', 'Bad pot goal')
        ->set('targetAmount', '1000.00')
        ->set('targetDate', '2027-01-01')
        ->set('linkedPotId', (string) $foreignPot->id)
        ->call('createGoal')
        ->assertNotDispatched('toast');

    $this->assertDatabaseMissing('goals', ['name' => 'Bad pot goal']);
});

it('resets the form instead of writing when updating a goal the user does not own', function (): void {
    $other = User::create([
        'username' => 'mallory',
        'password' => 'x',
        'period_start_day' => 1,
    ]);
    $foreignGoal = Goal::factory()->create([
        'user_id' => $other->id,
        'name' => 'Mallory goal',
        'status' => 'active',
    ]);

    // A valid name + date clears validation so the write is attempted; the
    // cross-user id then raises GoalNotFoundException, which resets the form
    // rather than surfacing a field error.
    Livewire::test(GoalsPage::class, ['editGoalId' => $foreignGoal->id])
        ->set('name', 'Hacked')
        ->set('targetAmount', '100.00')
        ->set('targetDate', '2027-01-01')
        ->call('updateGoal')
        ->assertNotDispatched('toast')
        ->assertSet('editGoalId', 0)
        ->assertSet('name', '');

    $this->assertDatabaseMissing('goals', ['id' => $foreignGoal->id, 'name' => 'Hacked']);
});

it('links a selected pot to the goal on create', function (): void {
    $pot = Pot::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'goal_id' => null,
        'category_id' => null,
    ]);

    Livewire::test(GoalsPage::class)
        ->set('name', 'Linked goal')
        ->set('targetAmount', '1000.00')
        ->set('targetDate', '2027-01-01')
        ->set('linkedPotId', (string) $pot->id)
        ->call('createGoal')
        ->assertDispatched('toast');

    $goal = Goal::query()->where('name', 'Linked goal')->firstOrFail();
    $this->assertDatabaseHas('pots', ['id' => $pot->id, 'goal_id' => $goal->id]);
});

it('relinks the goal to a different pot on update', function (): void {
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);
    $potA = Pot::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'goal_id' => $goal->id,
    ]);
    $potB = Pot::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'goal_id' => null,
    ]);

    Livewire::test(GoalsPage::class, ['editGoalId' => $goal->id])
        ->set('name', 'Renamed')
        ->set('targetAmount', '500.00')
        ->set('targetDate', '2027-06-01')
        ->set('linkedPotId', (string) $potB->id)
        ->call('updateGoal')
        ->assertDispatched('toast');

    $this->assertDatabaseHas('pots', ['id' => $potB->id, 'goal_id' => $goal->id]);
    $this->assertDatabaseHas('pots', ['id' => $potA->id, 'goal_id' => null]);
});

it('clears the pot link when the picker is emptied on update', function (): void {
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);
    $pot = Pot::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'goal_id' => $goal->id,
    ]);

    Livewire::test(GoalsPage::class, ['editGoalId' => $goal->id])
        ->set('name', 'Renamed')
        ->set('targetAmount', '500.00')
        ->set('targetDate', '2027-06-01')
        ->set('linkedPotId', '')
        ->call('updateGoal')
        ->assertDispatched('toast');

    $this->assertDatabaseHas('pots', ['id' => $pot->id, 'goal_id' => null]);
});

it('cross-user cannot archive another users goal', function (): void {
    $other = User::create([
        'username' => 'mallory',
        'password' => 'x',
        'period_start_day' => 1,
    ]);
    $foreignGoal = Goal::factory()->create([
        'user_id' => $other->id,
        'status' => 'active',
    ]);

    Livewire::test(GoalsPage::class)
        ->call('archive', $foreignGoal->id);

    // Still active: the BelongsToUser scope never resolved the foreign id.
    $this->assertDatabaseHas('goals', [
        'id' => $foreignGoal->id,
        'status' => 'active',
    ]);
});

// "Building a projection…" read as work in flight, but it is the terminal
// branch for a goal whose contributions carry no measurable rate, with no job
// behind it — the reader waited for a computation that had never started.
it('tells a goal with no measurable rate that history is short, not that work is running', function (): void {
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'target_minor' => 120000,
        'start_date' => CarbonImmutable::now()->toDateString(),
        'target_date' => CarbonImmutable::now()->addYearNoOverflow()->toDateString(),
        'status' => 'active',
    ]);

    $run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/goals-projection.xml',
        'sha256' => str_repeat('c', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $transaction = Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'transfer_in',
        'posted_at' => CarbonImmutable::now()->toDateString(),
        'booked_at' => CarbonImmutable::now()->toDateString().' 12:00:00',
        'value_date' => CarbonImmutable::now()->toDateString(),
        'amount_minor' => 50000,
        'currency' => 'EUR',
        'settled_amount_minor' => 50000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Vakantiepot',
        'counterparty_normalized' => 'vakantiepot',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('goalproj', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    app(GoalContributionWriter::class)->attribute($this->user, $goal->id, $transaction->id);

    expect(app(GoalProgressQuery::class)->forUser($this->user)[0]->projectedFinishDate)->toBeNull();

    Livewire::test(GoalsPage::class)
        ->assertOk()
        ->assertSee(Lang::get('goals::messages.projection.not_enough_history'))
        ->assertDontSee('Building a projection');
});

// The page splits at 768px into a phone list and a desktop list. The phone
// branch carried a bare percentage: no bar, and no sign of the target date the
// create form refuses to submit without.
it('gives the phone list the same progress bar the desktop list has', function (): void {
    Goal::create([
        'user_id' => $this->user->id,
        'name' => 'Noodfonds',
        'target_minor' => 500000,
        'currency' => 'EUR',
        'start_date' => CarbonImmutable::now()->toDateString(),
        'target_date' => CarbonImmutable::now()->addMonthsNoOverflow(4)->toDateString(),
        'status' => 'active',
    ]);

    $html = (string) Livewire::test(GoalsPage::class)->html();
    // Both class names also appear in the media query at the top, so slice on
    // the LAST occurrence — the markup, not the stylesheet.
    $phone = substr($html, (int) strrpos($html, 'goals-phone-list'), (int) strrpos($html, 'goals-desktop-list') - (int) strrpos($html, 'goals-phone-list'));

    expect($phone)->toContain('role="progressbar"');
});

it('tells the phone what it says about the finish date, not only a percentage', function (): void {
    Goal::create([
        'user_id' => $this->user->id,
        'name' => 'Noodfonds',
        'target_minor' => 500000,
        'currency' => 'EUR',
        'start_date' => CarbonImmutable::now()->toDateString(),
        'target_date' => CarbonImmutable::now()->addMonthsNoOverflow(4)->toDateString(),
        'status' => 'active',
    ]);

    $html = (string) Livewire::test(GoalsPage::class)->html();
    // Both class names also appear in the media query at the top, so slice on
    // the LAST occurrence — the markup, not the stylesheet.
    $phone = substr($html, (int) strrpos($html, 'goals-phone-list'), (int) strrpos($html, 'goals-desktop-list') - (int) strrpos($html, 'goals-phone-list'));

    // Nothing contributed yet, so the honest line is the one asking for some.
    expect($phone)->toContain(Lang::get('goals::messages.projection.add_contributions'));
});

// ios-15: the form refuses a goal without a target date, and then neither list
// rendered it — `targetDate` appeared only in the three wire:model bindings, so
// the only way back to it was reopening the edit sheet.

/** @return array{phone: string, desktop: string} the two list branches, sliced out of one render */
function goalsListBranches(string $html): array
{
    // Both class names also appear in the media query at the top, so slice on
    // the LAST occurrence — the markup, not the stylesheet.
    $phoneAt = (int) strrpos($html, 'goals-phone-list');
    $desktopAt = (int) strrpos($html, 'goals-desktop-list');

    return [
        'phone' => substr($html, $phoneAt, $desktopAt - $phoneAt),
        'desktop' => substr($html, $desktopAt),
    ];
}

function goalWithTargetDate(int $userId, string $targetDate, string $status = 'active'): void
{
    Goal::create([
        'user_id' => $userId,
        'name' => 'Noodfonds',
        'target_minor' => 500000,
        'currency' => 'EUR',
        'start_date' => CarbonImmutable::now()->toDateString(),
        'target_date' => $targetDate,
        'status' => $status,
    ]);
}

it('shows the target date the form insisted on in the phone list', function (): void {
    goalWithTargetDate((int) $this->user->id, '2026-12-31');

    $branches = goalsListBranches((string) Livewire::test(GoalsPage::class)->html());

    expect($branches['phone'])->toContain(
        Lang::get('goals::messages.card.target_date', ['date' => '31 Dec 2026'])
    );
});

it('shows the target date in the desktop list too', function (): void {
    goalWithTargetDate((int) $this->user->id, '2026-12-31');

    $branches = goalsListBranches((string) Livewire::test(GoalsPage::class)->html());

    expect($branches['desktop'])->toContain(
        Lang::get('goals::messages.card.target_date', ['date' => '31 Dec 2026'])
    );
});

// The finding's secondary half: the phone status conditional covered
// overdue/reached only, so the Completed badge existed on the desktop branch
// alone and a finished goal read as an unfinished one at 375pt.
it('tells the phone list a completed goal is completed, as the desktop list already did', function (): void {
    goalWithTargetDate((int) $this->user->id, '2026-12-31', 'completed');

    $branches = goalsListBranches((string) Livewire::test(GoalsPage::class)->html());

    expect($branches['phone'])->toContain(Lang::get('goals::messages.status.completed'));
});

// The desktop card qualified a beyond-horizon date with "(projection)"; the
// phone list carried its own copy of the same six-branch chain and that copy had
// dropped the qualifier, so an estimate read as a hard date at 375pt. Both
// surfaces render one partial now, and this is what says the phone gets it.
it('qualifies a beyond-horizon estimate as a projection on the phone list, as the desktop card already did', function (): void {
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Noodfonds',
        'target_minor' => 1000000,
        'start_date' => CarbonImmutable::now()->subDays(30)->toDateString(),
        'target_date' => CarbonImmutable::now()->addYearsNoOverflow(3)->toDateString(),
        'status' => 'active',
    ]);

    $run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/goals-horizon.xml',
        'sha256' => str_repeat('d', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    // 1 000 minor over 30 days is ~33 minor a day against a 1 000 000 target, so
    // the finish date lands far past the 90-day horizon.
    $transaction = Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'transfer_in',
        'posted_at' => CarbonImmutable::now()->subDays(15)->toDateString(),
        'booked_at' => CarbonImmutable::now()->subDays(15)->toDateString().' 12:00:00',
        'value_date' => CarbonImmutable::now()->subDays(15)->toDateString(),
        'amount_minor' => 1000,
        'currency' => 'EUR',
        'settled_amount_minor' => 1000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Noodfonds',
        'counterparty_normalized' => 'noodfonds',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('goalhorizon', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    app(GoalContributionWriter::class)->attribute($this->user, $goal->id, $transaction->id);

    expect(app(GoalProgressQuery::class)->forUser($this->user)[0]->projectionBeyondHorizon)->toBeTrue();

    $branches = goalsListBranches((string) Livewire::test(GoalsPage::class)->html());

    expect($branches['phone'])->toContain(Lang::get('goals::messages.projection.projection_note'))
        ->and($branches['desktop'])->toContain(Lang::get('goals::messages.projection.projection_note'));
});

// Both lists applied the 2%-minimum sliver themselves, under two names. The rule
// belongs to the row, and a bar the reader cannot see is the failure it prevents.
it('draws the same minimum sliver in both lists for a goal with a tiny but real share', function (): void {
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Noodfonds',
        'target_minor' => 100000,
        'start_date' => CarbonImmutable::now()->subDays(30)->toDateString(),
        'target_date' => CarbonImmutable::now()->addYearNoOverflow()->toDateString(),
        'status' => 'active',
    ]);

    $run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/goals-sliver.xml',
        'sha256' => str_repeat('e', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $transaction = Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'transfer_in',
        'posted_at' => CarbonImmutable::now()->subDays(15)->toDateString(),
        'booked_at' => CarbonImmutable::now()->subDays(15)->toDateString().' 12:00:00',
        'value_date' => CarbonImmutable::now()->subDays(15)->toDateString(),
        'amount_minor' => 1000,
        'currency' => 'EUR',
        'settled_amount_minor' => 1000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Noodfonds',
        'counterparty_normalized' => 'noodfonds',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('goalsliver', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    app(GoalContributionWriter::class)->attribute($this->user, $goal->id, $transaction->id);

    $row = app(GoalProgressQuery::class)->forUser($this->user)[0];

    expect($row->percentComplete())->toBe(1)
        ->and($row->barWidth())->toBe(2);

    $branches = goalsListBranches((string) Livewire::test(GoalsPage::class)->html());

    expect($branches['phone'])->toContain('aria-valuenow="2"')
        ->and($branches['desktop'])->toContain('aria-valuenow="2"');
});

/** @return array<string, string> the element's attributes, keyed by name */
function goalsElementAttributes(string $html, string $id): array
{
    $dom = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8"?><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $element = (new DOMXPath($dom))->query('//*[@id="'.$id.'"]')->item(0);
    if (! $element instanceof DOMElement) {
        return [];
    }

    $attributes = ['#text' => trim($element->textContent)];
    foreach ($element->attributes as $attribute) {
        $attributes[$attribute->nodeName] = $attribute->nodeValue ?? '';
    }

    return $attributes;
}

it('points every phone-sheet field at its own error line', function (): void {
    // One render carrying all three errors: the form validates in sequence and
    // stops at the first, so driving them through createGoal would need three.
    $html = (string) Livewire::test(GoalsPage::class)
        ->set('errorName', 'Enter a name for your goal.')
        ->set('errorAmount', 'Enter a target amount.')
        ->set('errorDate', 'Choose a target date.')
        ->html();

    foreach (['goal-name-sheet' => 'Enter a name for your goal.',
        'goal-amount-sheet' => 'Enter a target amount.',
        'goal-date-sheet' => 'Choose a target date.'] as $fieldId => $message) {
        $field = goalsElementAttributes($html, $fieldId);
        $error = goalsElementAttributes($html, $fieldId.'-error');

        expect($field['aria-invalid'] ?? null)->toBe('true', $fieldId.' is not marked invalid')
            ->and($field['aria-describedby'] ?? null)->toBe($fieldId.'-error', $fieldId.' points at no error line')
            ->and($error['#text'] ?? null)->toBe($message, $fieldId.'-error is not the error paragraph');
    }
});

// "Mark as complete" is a lifecycle action with no target check, and the
// projection line read status where it means to read progress: a goal closed
// at EUR300.00 of EUR600.00 printed "Target reached" one line under the two
// figures that say it was not.
it('does not tell a goal closed short of its target that the target was reached', function (): void {
    $goal = Goal::create([
        'user_id' => $this->user->id,
        'name' => 'Winter tyres',
        'target_minor' => 60000,
        'target_currency' => 'EUR',
        'start_date' => CarbonImmutable::now()->toDateString(),
        'target_date' => CarbonImmutable::now()->addMonthsNoOverflow(3)->toDateString(),
        'status' => 'completed',
    ]);
    Pot::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'name' => 'Winter tyres',
        'goal_id' => $goal->id,
        'currency' => 'EUR',
        'status' => 'active',
    ]);
    DB::table('pot_movements')->insert([
        'user_id' => $this->user->id,
        'pot_id' => (int) Pot::query()->where('goal_id', $goal->id)->value('id'),
        'amount_minor' => 30000,
        'currency' => 'EUR',
        'kind' => 'fund',
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    $branches = goalsListBranches((string) Livewire::test(GoalsPage::class)->html());

    expect($branches['desktop'])->not->toContain(Lang::get('goals::messages.projection.target_reached'))
        ->and($branches['phone'])->not->toContain(Lang::get('goals::messages.projection.target_reached'))
        ->and($branches['desktop'])->toContain(Lang::get('goals::messages.projection.closed_short'));
});

it('still tells a goal completed on its target that the target was reached', function (): void {
    $goal = Goal::create([
        'user_id' => $this->user->id,
        'name' => 'Winter tyres',
        'target_minor' => 60000,
        'target_currency' => 'EUR',
        'start_date' => CarbonImmutable::now()->subMonthsNoOverflow(2)->toDateString(),
        'target_date' => CarbonImmutable::now()->addMonthsNoOverflow(3)->toDateString(),
        'status' => 'completed',
    ]);
    $pot = Pot::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'name' => 'Winter tyres',
        'goal_id' => $goal->id,
        'currency' => 'EUR',
        'status' => 'active',
    ]);
    DB::table('pot_movements')->insert([
        'user_id' => $this->user->id,
        'pot_id' => $pot->id,
        'amount_minor' => 60000,
        'currency' => 'EUR',
        'kind' => 'fund',
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    $branches = goalsListBranches((string) Livewire::test(GoalsPage::class)->html());

    expect($branches['desktop'])->toContain(Lang::get('goals::messages.projection.target_reached'));
});
