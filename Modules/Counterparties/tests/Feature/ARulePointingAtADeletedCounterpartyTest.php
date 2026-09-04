<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Categorization\Public\Actions\CreateCategorizationRule;
use Modules\Categorization\Public\Dto\RuleInput;
use Modules\Core\Models\User;
use Modules\Sync\Public\Events\EntityMutated;

uses(RefreshDatabase::class);

// `rule_actions.payload` embeds a counterparty_id as opaque JSON with no FK, so
// a counterparty leaving the table has to deactivate the owning rule. Nothing
// deletes one today, and a writer that ever does has to announce it — this is
// the listener contract that turns such an announcement into the deactivation,
// asserted by dispatching the event a writer would.

function ruleReferentUser(): User
{
    return User::query()->create([
        'username' => 'deleted-cp-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
    ]);
}

function ruleReferentCounterparty(int $userId, string $slug): int
{
    $now = now()->toDateTimeString();

    return (int) DB::table('counterparties')->insertGetId([
        'user_id' => $userId,
        'type' => 'merchant',
        'slug' => $slug,
        'display_name' => 'Deleted '.$slug,
        'iban' => null,
        'merchant_name' => null,
        'metadata' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function ruleReferentRule(User $user, int $counterpartyId): int
{
    return (app(CreateCategorizationRule::class))($user, new RuleInput(
        priority: 10,
        combinator: 'all',
        active: true,
        notes: null,
        conditions: [['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'DELETED']],
        actions: [['type' => 'counterparty', 'payload' => ['counterparty_id' => $counterpartyId]]],
    ));
}

function announceCounterpartyDelete(int $userId, int $counterpartyId): void
{
    app(Dispatcher::class)->dispatch(new EntityMutated(
        table: 'counterparties',
        pk: $counterpartyId,
        userId: $userId,
        mutationType: 'delete',
    ));
}

it('deactivates a rule whose action names a counterparty a writer announced deleting', function (): void {
    $user = ruleReferentUser();
    $counterpartyId = ruleReferentCounterparty($user->id, 'deleted-merchant-'.bin2hex(random_bytes(3)));
    $ruleId = ruleReferentRule($user, $counterpartyId);

    expect((bool) DB::table('categorization_rules')->where('id', $ruleId)->value('active'))->toBeTrue();

    announceCounterpartyDelete($user->id, $counterpartyId);

    expect((bool) DB::table('categorization_rules')->where('id', $ruleId)->value('active'))->toBeFalse();
});

it('leaves another reader\'s rule alone when a counterparty delete is announced', function (): void {
    $owner = ruleReferentUser();
    $other = ruleReferentUser();

    $ownerCounterpartyId = ruleReferentCounterparty($owner->id, 'deleted-owner-'.bin2hex(random_bytes(3)));
    $otherCounterpartyId = ruleReferentCounterparty($other->id, 'deleted-other-'.bin2hex(random_bytes(3)));

    $otherRuleId = ruleReferentRule($other, $otherCounterpartyId);
    ruleReferentRule($owner, $ownerCounterpartyId);

    announceCounterpartyDelete($owner->id, $ownerCounterpartyId);

    expect((bool) DB::table('categorization_rules')->where('id', $otherRuleId)->value('active'))->toBeTrue();
});

// The deactivation is scoped to the announced id, not to the run, so a rule
// naming any other counterparty keeps working.
it('leaves a rule naming a counterparty nobody announced active', function (): void {
    $user = ruleReferentUser();

    $goneId = ruleReferentCounterparty($user->id, 'deleted-gone-'.bin2hex(random_bytes(3)));
    $keptId = ruleReferentCounterparty($user->id, 'deleted-kept-'.bin2hex(random_bytes(3)));

    $keptRuleId = ruleReferentRule($user, $keptId);
    ruleReferentRule($user, $goneId);

    announceCounterpartyDelete($user->id, $goneId);

    expect(DB::table('counterparties')->where('id', $keptId)->count())->toBe(1)
        ->and((bool) DB::table('categorization_rules')->where('id', $keptRuleId)->value('active'))->toBeTrue();
});
