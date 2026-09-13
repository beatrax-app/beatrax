<?php

declare(strict_types=1);

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\ManageUserPage;
use Modules\Auth\Models\UserRecoveryCode;
use Modules\Core\Models\User;

// Both writes on this page hand out a way into an account the reader is not
// signed in as, and neither is retired by that account's own password change.
// Owner authority alone is a property of the session, so a session somebody
// else is holding carries it — which is what the owner's password is asked for.

const PARTNER_PROOF_OWNER_PASSWORD = 'owner-proof-password-12';

function partnerProofOwner(): User
{
    /** @var User $owner */
    $owner = User::query()->create([
        'username' => 'proof-owner',
        'password' => PARTNER_PROOF_OWNER_PASSWORD,
        'period_start_day' => 1,
        'is_developer' => true,
    ]);

    return $owner;
}

function partnerProofPartner(): User
{
    /** @var User $partner */
    $partner = User::query()->create([
        'username' => 'proof-partner',
        'password' => 'partner-proof-password-12',
        'period_start_day' => 1,
        'is_developer' => false,
    ]);

    return $partner;
}

/**
 * @return list<string> the partner's code hashes still standing unused
 */
function partnerProofStandingHashes(int $partnerId): array
{
    return UserRecoveryCode::withoutGlobalScopes()
        ->where('user_id', $partnerId)
        ->whereNull('used_at')
        ->orderBy('code_hash')
        ->pluck('code_hash')
        ->map(static fn (mixed $hash): string => (string) $hash)
        ->values()
        ->all();
}

function partnerProofSeedSheet(int $partnerId): void
{
    for ($i = 0; $i < 10; $i++) {
        UserRecoveryCode::query()->create([
            'user_id' => $partnerId,
            'code_hash' => bcrypt('standing-code-'.$i),
            'used_at' => null,
        ]);
    }
}

it('mints no partner sheet where the owner types no password of their own', function (): void {
    $owner = partnerProofOwner();
    $partner = partnerProofPartner();
    partnerProofSeedSheet($partner->id);

    $before = partnerProofStandingHashes($partner->id);

    $component = Livewire::actingAs($owner)->test(ManageUserPage::class, ['username' => 'proof-partner'])
        ->call('regenerateCodes');

    expect(partnerProofStandingHashes($partner->id))->toBe(
        $before,
        'owner authority alone retired the partner sheet and minted one the session holder keeps',
    );

    expect($component->get('regeneratedCodes'))->toBe(
        [],
        'ten codes into another account were drawn on the wire for a caller that proved nothing',
    );
});

it('mints no partner sheet where the typed password is not the owner\'s', function (): void {
    $owner = partnerProofOwner();
    $partner = partnerProofPartner();
    partnerProofSeedSheet($partner->id);

    $before = partnerProofStandingHashes($partner->id);

    // The partner's own password is the near miss worth naming: it is the
    // credential being replaced, and it is not the one that authorises this.
    $component = Livewire::actingAs($owner)->test(ManageUserPage::class, ['username' => 'proof-partner'])
        ->set('ownerPassword', 'partner-proof-password-12')
        ->call('regenerateCodes');

    expect(partnerProofStandingHashes($partner->id))->toBe($before);
    expect($component->get('regeneratedCodes'))->toBe([]);
});

it('mints the partner sheet once the owner proves they are the owner', function (): void {
    $owner = partnerProofOwner();
    $partner = partnerProofPartner();
    partnerProofSeedSheet($partner->id);

    $before = partnerProofStandingHashes($partner->id);
    expect($before)->toHaveCount(10);

    $component = Livewire::actingAs($owner)->test(ManageUserPage::class, ['username' => 'proof-partner'])
        ->set('ownerPassword', PARTNER_PROOF_OWNER_PASSWORD)
        ->call('regenerateCodes');

    $after = partnerProofStandingHashes($partner->id);

    expect($after)->toHaveCount(10)
        ->and(array_intersect($after, $before))->toBe([]);

    expect($component->get('regeneratedCodes'))->toHaveCount(10);
    expect($component->get('ownerPassword'))->toBe('');
});

it('sets no partner password where the owner types no password of their own', function (): void {
    $owner = partnerProofOwner();
    $partner = partnerProofPartner();

    DB::table('sessions')->insert([
        'id' => 'proof-partner-session',
        'user_id' => $partner->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'seeded',
        'payload' => base64_encode(serialize([])),
        'last_activity' => time(),
    ]);

    Livewire::actingAs($owner)->test(ManageUserPage::class, ['username' => 'proof-partner'])
        ->set('newPartnerPassword', 'chosen-by-the-caller-1')
        ->call('setPartnerPassword');

    /** @var Hasher $hasher */
    $hasher = $this->app->make(Hasher::class);
    $fresh = $partner->fresh();

    expect($hasher->check('chosen-by-the-caller-1', (string) $fresh?->password))->toBeFalse(
        'the partner password was set to one the caller chose, which is the partner account handed over',
    );

    expect($fresh?->force_password_change_at_next_login)->toBeFalse();
    expect(DB::table('sessions')->where('user_id', $partner->id)->count())->toBe(1);
});

it('sets the partner password once the owner proves they are the owner', function (): void {
    $owner = partnerProofOwner();
    $partner = partnerProofPartner();

    $component = Livewire::actingAs($owner)->test(ManageUserPage::class, ['username' => 'proof-partner'])
        ->set('newPartnerPassword', 'partner-chosen-anew-12')
        ->set('ownerPassword', PARTNER_PROOF_OWNER_PASSWORD)
        ->call('setPartnerPassword');

    /** @var Hasher $hasher */
    $hasher = $this->app->make(Hasher::class);
    $fresh = $partner->fresh();

    expect($hasher->check('partner-chosen-anew-12', (string) $fresh?->password))->toBeTrue();
    expect($fresh?->force_password_change_at_next_login)->toBeTrue();
    expect($component->get('ownerPassword'))->toBe('');
});
