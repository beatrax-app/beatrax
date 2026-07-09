<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Categorization\Internal\Http\Livewire\RuleFormModal;
use Modules\Categorization\Public\Actions\CreateCategorizationRule;
use Modules\Categorization\Public\Services\CategorizationRuleQuery;
use Modules\Core\Models\User;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(EnablesEncryptionForUser::class);

/*
 * 14.1-10 Task 1 (D-06 cosmetic-display cluster) — under an encrypted
 * user, `counterparties.display_name` is ciphertext at rest.
 * RuleFormModal's counterparty picker and CategorizationRuleQuery's
 * counterparty-action label must decrypt it for display rather than
 * leaking ciphertext into a dropdown/label. Both sites also dropped
 * their ORDER BY on the ciphertext column.
 */

function ddUser(): User
{
    return User::query()->create([
        'username' => 'dd-user-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

/**
 * Inserts a counterparty row whose display_name is ciphertext at rest
 * — mirrors what CounterpartyResolverService's creation-time write
 * would have produced for an already-encrypted user.
 */
function ddEncryptedCounterparty(User $user, Session $session, string $slug, string $displayName): int
{
    /** @var SensitiveColumnCodec $codec */
    $codec = Container::getInstance()->make(SensitiveColumnCodec::class);
    $ciphertext = $codec->encryptValue('counterparties', 'display_name', $displayName, (int) $user->id, $session);

    // A pass-through (encryption not usable) would return the value
    // unchanged — assert it actually encrypted so this fixture never
    // silently degrades into a vacuous test.
    expect($ciphertext)->not->toBe($displayName);

    return (int) DB::table('counterparties')->insertGetId([
        'user_id' => $user->id,
        'type' => 'merchant',
        'slug' => $slug,
        'display_name' => $ciphertext,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

it('RuleFormModal renders decrypted counterparty display_name in the picker (no ciphertext ORDER BY)', function (): void {
    $user = ddUser();
    $session = $this->enablesEncryptionForUser($user);
    $this->actingAs($user);

    ddEncryptedCounterparty($user, $session, 'dd-zebra', 'Zebra Traders');
    ddEncryptedCounterparty($user, $session, 'dd-alpha', 'Alpha Traders');

    $html = Livewire::test(RuleFormModal::class)
        ->call('open', ruleId: null)
        ->html();

    expect($html)->toContain('Zebra Traders');
    expect($html)->toContain('Alpha Traders');

    // Ciphertext must never leak into the rendered HTML.
    $stored = DB::table('counterparties')->where('user_id', $user->id)->pluck('display_name');
    foreach ($stored as $cipher) {
        expect($html)->not->toContain($cipher);
    }
});

it('CategorizationRuleQuery decrypts the counterparty-action label', function (): void {
    $user = ddUser();
    $session = $this->enablesEncryptionForUser($user);
    $this->actingAs($user);

    $counterpartyId = ddEncryptedCounterparty($user, $session, 'dd-gym', 'Neighbourhood Gym');

    /** @var CreateCategorizationRule $create */
    $create = Container::getInstance()->make(CreateCategorizationRule::class);
    $create(
        $user,
        10,
        'all',
        true,
        null,
        [['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'GYM']],
        [['type' => 'counterparty', 'payload' => ['counterparty_id' => $counterpartyId]]],
    );

    /** @var CategorizationRuleQuery $query */
    $query = Container::getInstance()->make(CategorizationRuleQuery::class);
    $rules = $query->forUser($user);

    expect($rules)->toHaveCount(1);
    expect($rules[0]->actions[0]->counterpartyName)->toBe('Neighbourhood Gym');
});
