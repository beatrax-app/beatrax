<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Public\Support\PatternScan;
use Modules\Goals\Public\Services\GoalWriter;
use Modules\Ledger\Internal\Http\Livewire\TransactionDetail;
use Modules\Ledger\Models\Account;

// The select carries the goal id as an option value, which is a string, and the
// button beside it decides what reaches the component. A goal id is minted from
// random_int(1, PHP_INT_MAX), so it is past 2^53 about 999 times in a thousand:
// whatever the button does to that string is what decides whether the reader's
// attribution lands at all.

const AGISA_JS_EXACT_INTEGER_MAX = 9007199254740991;

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-14 12:00:00'));

    $account = Account::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('iban', 'NL57ASNB0123456789')
        ->firstOrFail();

    $this->tx = $this->makeTransaction($this->fixtureUser, $account, $this->makeImportRun($this->fixtureUser), [
        'type' => 'transfer_in',
        'amount_minor' => 20000,
        'posted_at' => '2026-06-14',
        'booked_at' => '2026-06-14 12:00:00',
    ]);

    // Through the writer, not the factory: the id is the whole subject here and
    // a fixture that hands out 1 cannot tell a rounded id from an intact one.
    $this->goal = app(GoalWriter::class)->save($this->fixtureUser, 'Winter tyres', '600,00', '2027-06-14');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// The option value, read off the rendered select rather than assumed: this is
// the exact string the browser holds.
function agisaOptionValueFor(string $html, string $goalName): string
{
    $options = PatternScan::all('/<option value="([^"]*)"[^>]*>\s*'.preg_quote($goalName, '/').'\s*<\/option>/', $html);

    return $options[1][0] ?? '';
}

function agisaSubmitHandler(string $html): string
{
    $handlers = PatternScan::all('/x-on:click="([^"]*)"(?=[^>]*data-testid="goal-attribution-submit")/', $html);

    return $handlers[1][0] ?? '';
}

// What the browser actually hands the component. A handler that converts the
// option value to a JavaScript number sends an IEEE double, and JSON writes it
// back out as the rounded integer; one that passes the value through sends the
// string it was given.
function agisaValueTheBrowserSends(string $handler, string $optionValue): int|string
{
    return PatternScan::matches('/(Number|parseInt|parseFloat)\s*\(\s*selectedGoal/', $handler)
        ? (int) (float) $optionValue
        : $optionValue;
}

it('mints a goal id no JavaScript number can hold, so the reading below is not answering an easy case', function (): void {
    expect($this->goal->id)->toBeGreaterThan(AGISA_JS_EXACT_INTEGER_MAX);
});

it('attributes the goal the reader picked, with the id the button actually sends', function (): void {
    $component = Livewire::test(TransactionDetail::class, ['transactionId' => $this->tx->id]);
    $html = $component->html();

    $optionValue = agisaOptionValueFor($html, 'Winter tyres');
    expect($optionValue)->toBe((string) $this->goal->id, 'The picker no longer offers the goal, so nothing below is being read.');

    $handler = agisaSubmitHandler($html);
    expect(str_contains($handler, 'attributeToGoal'))
        ->toBeTrue('The submit button no longer calls the action, so nothing below is being read.');

    $component->call('attributeToGoal', agisaValueTheBrowserSends($handler, $optionValue));

    expect(DB::table('goal_contributions')
        ->where('goal_id', $this->goal->id)
        ->where('transaction_id', $this->tx->id)
        ->count())->toBe(1);
});
