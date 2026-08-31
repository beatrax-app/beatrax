<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Categorization\Public\Actions\CreateCategorizationRule;
use Modules\Categorization\Public\Dto\RuleInput;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Jobs\CounterpartyGarbageCollectorJob;

uses(RefreshDatabase::class);

// `rule_actions.payload` embeds a counterparty_id as opaque JSON with no FK, so
// deleting the row it names has to deactivate the owning rule. The listener that
// does it hangs off the Eloquent model event, and the collector — the only thing
// in production that ever deletes a counterparty — deletes through the query
// builder, which fires none.

function prunedCpUser(): User
{
    return User::query()->create([
        'username' => 'pruned-cp-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
    ]);
}

function prunedCpCounterparty(int $userId, string $slug): int
{
    $now = now()->toDateTimeString();

    return (int) DB::table('counterparties')->insertGetId([
        'user_id' => $userId,
        'type' => 'merchant',
        'slug' => $slug,
        'display_name' => 'Pruned '.$slug,
        'iban' => null,
        'merchant_name' => null,
        'metadata' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function prunedCpRule(User $user, int $counterpartyId): int
{
    return (app(CreateCategorizationRule::class))($user, new RuleInput(
        priority: 10,
        combinator: 'all',
        active: true,
        notes: null,
        conditions: [['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'PRUNED']],
        actions: [['type' => 'counterparty', 'payload' => ['counterparty_id' => $counterpartyId]]],
    ));
}

it('deactivates a rule whose action names a counterparty the collector pruned', function (): void {
    $user = prunedCpUser();
    $counterpartyId = prunedCpCounterparty($user->id, 'pruned-merchant-'.bin2hex(random_bytes(3)));
    $ruleId = prunedCpRule($user, $counterpartyId);

    expect((bool) DB::table('categorization_rules')->where('id', $ruleId)->value('active'))->toBeTrue();

    $this->app->call([new CounterpartyGarbageCollectorJob($user->id), 'handle']);

    expect(DB::table('counterparties')->where('id', $counterpartyId)->count())->toBe(0)
        ->and((bool) DB::table('categorization_rules')->where('id', $ruleId)->value('active'))->toBeFalse();
});

it('leaves another reader\'s rule alone when the collector prunes', function (): void {
    $owner = prunedCpUser();
    $other = prunedCpUser();

    $ownerCounterpartyId = prunedCpCounterparty($owner->id, 'pruned-owner-'.bin2hex(random_bytes(3)));
    $otherCounterpartyId = prunedCpCounterparty($other->id, 'pruned-other-'.bin2hex(random_bytes(3)));

    $otherRuleId = prunedCpRule($other, $otherCounterpartyId);
    prunedCpRule($owner, $ownerCounterpartyId);

    $this->app->call([new CounterpartyGarbageCollectorJob($owner->id), 'handle']);

    expect((bool) DB::table('categorization_rules')->where('id', $otherRuleId)->value('active'))->toBeTrue();
});

// A rule naming a counterparty the collector had no reason to touch keeps
// working: the deactivation is scoped to the pruned id, not to the run.
it('leaves a rule naming a surviving counterparty active', function (): void {
    $user = prunedCpUser();

    $prunedId = prunedCpCounterparty($user->id, 'pruned-gone-'.bin2hex(random_bytes(3)));
    $keptId = (int) DB::table('counterparties')->insertGetId([
        'user_id' => $user->id,
        'type' => 'merchant',
        'slug' => 'pruned-kept-'.bin2hex(random_bytes(3)),
        'display_name' => 'Kept Merchant',
        'iban' => null,
        // A merchant_name matching an alias is what keeps a row out of the
        // orphan set, so this one survives the same run.
        'merchant_name' => 'KEPT MERCHANT',
        'metadata' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    DB::table('merchant_aliases')->insert([
        'user_id' => $user->id,
        'pattern' => 'KEPT MERCHANT',
        'generalized_pattern' => 'kept merchant',
        'friendly_name' => 'KEPT MERCHANT',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $keptRuleId = prunedCpRule($user, $keptId);
    prunedCpRule($user, $prunedId);

    $this->app->call([new CounterpartyGarbageCollectorJob($user->id), 'handle']);

    expect(DB::table('counterparties')->where('id', $keptId)->count())->toBe(1)
        ->and((bool) DB::table('categorization_rules')->where('id', $keptRuleId)->value('active'))->toBeTrue();
});
