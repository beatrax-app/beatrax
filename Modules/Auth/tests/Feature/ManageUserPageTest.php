<?php

declare(strict_types=1);

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\ManageUserPage;
use Modules\Auth\Models\UserRecoveryCode;
use Modules\Auth\Public\Actions\RegenerateRecoveryCodesAction;
use Modules\Core\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

function manageOwner(): User
{
    return User::query()->create([
        'username' => 'owner',
        'password' => 'owner-password-12chars',
        'period_start_day' => 1,
        'is_developer' => true,
    ]);
}

function manageSeedSession(int $userId, string $id): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $userId,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'seeded',
        'payload' => base64_encode(serialize([])),
        'last_activity' => time(),
    ]);
}

function managePartner(): User
{
    return User::query()->create([
        'username' => 'partner',
        'password' => 'partner-password-12ch',
        'period_start_day' => 1,
        'is_developer' => false,
    ]);
}

it('regenerates partner codes: stamps old unused codes used and inserts ten fresh rows', function (): void {
    $owner = manageOwner();
    $partner = managePartner();

    for ($i = 0; $i < 10; $i++) {
        UserRecoveryCode::query()->create([
            'user_id' => $partner->id,
            'code_hash' => bcrypt('seed-code-'.$i),
            'used_at' => null,
        ]);
    }

    /** @var RegenerateRecoveryCodesAction $regen */
    $regen = app(RegenerateRecoveryCodesAction::class);

    $newCodes = $regen($owner, 'partner');

    expect($newCodes)->toHaveCount(10);

    $unused = UserRecoveryCode::query()
        ->where('user_id', $partner->id)
        ->whereNull('used_at')
        ->count();
    expect($unused)->toBe(10);

    $used = UserRecoveryCode::query()
        ->where('user_id', $partner->id)
        ->whereNotNull('used_at')
        ->count();
    expect($used)->toBe(10);
});

// The caller here is a developer, so the ownership check is the only thing
// that can produce the 404 -- which is the point: is_developer is self-settable
// from /settings, and the action never reads it.
it('throws a 404 when a caller who is not the owner regenerates another user codes', function (): void {
    // Owner first: the owner is the account created first, so the order the
    // fixture writes them in is the thing under test.
    manageOwner();
    $notTheOwner = managePartner();
    $notTheOwner->update(['is_developer' => true]);

    /** @var RegenerateRecoveryCodesAction $regen */
    $regen = app(RegenerateRecoveryCodesAction::class);

    expect(fn () => $regen($notTheOwner->fresh(), 'owner'))
        ->toThrow(NotFoundHttpException::class);
});

it('renders the manage page for a developer viewing the partner', function (): void {
    manageOwner();
    managePartner();

    $this->actingAs(User::query()->where('username', 'owner')->first())
        ->get('/settings/users/partner')
        ->assertOk()
        ->assertSeeText('Manage partner')
        ->assertSeeText('Set new password for this user')
        ->assertSeeText('Regenerate recovery codes for this user');
});

it('returns 404 from the manage route for a non-developer', function (): void {
    manageOwner();
    $partner = managePartner();

    $this->actingAs($partner)->get('/settings/users/owner')->assertNotFound();
});

it('returns 404 from the manage route for a non-existent username', function (): void {
    $owner = manageOwner();

    $this->actingAs($owner)->get('/settings/users/ghost')->assertNotFound();
});

it('sets a new partner password, flags a forced change and severs what the partner still holds', function (): void {
    /** @var Hasher $hasher */
    $hasher = app(Hasher::class);

    $owner = manageOwner();
    $partner = managePartner();

    DB::table('users')->where('id', $partner->id)->update(['remember_token' => 'manage-stale-token']);
    manageSeedSession($partner->id, 'manage-partner-session');
    manageSeedSession($owner->id, 'manage-owner-session');

    Livewire::actingAs($owner)->test(ManageUserPage::class, ['username' => 'partner'])
        ->set('newPartnerPassword', 'partner-new-password-1')
        ->call('setPartnerPassword');

    $fresh = $partner->fresh();
    expect($hasher->check('partner-new-password-1', $fresh->password))->toBeTrue();
    expect($fresh->force_password_change_at_next_login)->toBeTrue();

    // The forced change at next sign-in is not containment: without these the
    // partner's live session and remember cookie both outlived their password.
    expect(DB::table('sessions')->where('user_id', $partner->id)->count())->toBe(0);
    expect(DB::table('users')->where('id', $partner->id)->value('remember_token'))
        ->not->toBe('manage-stale-token');
    expect(DB::table('sessions')->where('user_id', $owner->id)->count())->toBe(1);
});

it('regenerates the partner codes from the manage page and displays them inline', function (): void {
    $owner = manageOwner();
    $partner = managePartner();

    UserRecoveryCode::query()->create([
        'user_id' => $partner->id,
        'code_hash' => bcrypt('one-old-code'),
        'used_at' => null,
    ]);

    $component = Livewire::actingAs($owner)->test(ManageUserPage::class, ['username' => 'partner'])
        ->call('regenerateCodes');

    $component->assertSeeText('Download as .txt');

    $codes = $component->get('regeneratedCodes');
    expect($codes)->toHaveCount(10);

    // The acting user is the owner, so the partner's codes fall outside the
    // BelongsToUser global scope and the assertion has to drop it.
    $unused = UserRecoveryCode::withoutGlobalScopes()
        ->where('user_id', $partner->id)
        ->whereNull('used_at')
        ->count();
    expect($unused)->toBe(10);
});
