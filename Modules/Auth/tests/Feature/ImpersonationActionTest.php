<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Session\Session;
use Modules\Auth\Public\Actions\EndImpersonationAction;
use Modules\Auth\Public\Actions\ImpersonateUserAction;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;

/*
 * Feature coverage for the profile-switch back-end: ImpersonateUserAction
 * (password-verified guard swap, audit rows, the refusal paths) and
 * EndImpersonationAction (restore-self, session clear, audit).
 */

beforeEach(function (): void {
    /** @var Hasher $hasher */
    $hasher = $this->app->make(Hasher::class);

    $this->password = 'developer-pass-phrase';

    $this->developer = User::query()->create([
        'username' => 'owner',
        'password' => $hasher->make($this->password),
        'is_developer' => true,
        'period_start_day' => 1,
    ]);

    $this->partner = User::query()->create([
        'username' => 'partner',
        'password' => $hasher->make('partner-pass-phrase'),
        'is_developer' => false,
        'period_start_day' => 1,
    ]);
});

it('swaps the guard, stashes the session keys, and audits a successful switch', function (): void {
    $this->actingAs($this->developer);

    /** @var ImpersonateUserAction $action */
    $action = $this->app->make(ImpersonateUserAction::class);

    $result = $action($this->password, $this->partner->id);

    expect($result->isSuccess())->toBeTrue();
    expect($result->actingAs?->id)->toBe($this->partner->id);

    /** @var AuthFactory $auth */
    $auth = $this->app->make(AuthFactory::class);
    expect($auth->guard()->id())->toBe($this->partner->id);

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    expect($session->get('auth.impersonating.original_user_id'))->toBe($this->developer->id);
    expect($session->get('auth.impersonating.original_username'))->toBe('owner');

    $alert = SystemAlert::query()->withoutGlobalScopes()->where('kind', 'auth.impersonation_started')->first();
    expect($alert)->not->toBeNull();
    expect($alert?->severity)->toBe('warning');
});

it('refuses a wrong developer password, leaves the guard, and audits a critical failure', function (): void {
    $this->actingAs($this->developer);

    /** @var ImpersonateUserAction $action */
    $action = $this->app->make(ImpersonateUserAction::class);

    $result = $action('not-the-password', $this->partner->id);

    expect($result->status)->toBe('wrong_password');
    expect($result->isSuccess())->toBeFalse();

    /** @var AuthFactory $auth */
    $auth = $this->app->make(AuthFactory::class);
    expect($auth->guard()->id())->toBe($this->developer->id);

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    expect($session->get('auth.impersonating.original_user_id'))->toBeNull();

    $alert = SystemAlert::query()->withoutGlobalScopes()->where('kind', 'auth.impersonation_failed')->first();
    expect($alert)->not->toBeNull();
    expect($alert?->severity)->toBe('critical');
});

it('refuses a non-developer caller without swapping the guard', function (): void {
    $this->actingAs($this->partner);

    /** @var ImpersonateUserAction $action */
    $action = $this->app->make(ImpersonateUserAction::class);

    $result = $action('partner-pass-phrase', $this->developer->id);

    expect($result->status)->toBe('not_allowed');

    /** @var AuthFactory $auth */
    $auth = $this->app->make(AuthFactory::class);
    expect($auth->guard()->id())->toBe($this->partner->id);
});

it('refuses a self-target with an invalid-target result', function (): void {
    $this->actingAs($this->developer);

    /** @var ImpersonateUserAction $action */
    $action = $this->app->make(ImpersonateUserAction::class);

    $result = $action($this->password, $this->developer->id);

    expect($result->status)->toBe('invalid_target');
});

it('refuses to nest a second switch while one is already active', function (): void {
    $this->actingAs($this->developer);

    /** @var ImpersonateUserAction $action */
    $action = $this->app->make(ImpersonateUserAction::class);

    $first = $action($this->password, $this->partner->id);
    expect($first->isSuccess())->toBeTrue();

    // The guard now resolves to the partner; a second call must refuse.
    $second = $action('partner-pass-phrase', $this->developer->id);
    expect($second->status)->toBe('not_allowed');
});

it('restores the original user, clears the keys, and audits when ending a switch', function (): void {
    $this->actingAs($this->developer);

    /** @var ImpersonateUserAction $start */
    $start = $this->app->make(ImpersonateUserAction::class);
    $start($this->password, $this->partner->id);

    /** @var EndImpersonationAction $end */
    $end = $this->app->make(EndImpersonationAction::class);
    $result = $end();

    expect($result->isSuccess())->toBeTrue();
    expect($result->actingAs?->id)->toBe($this->developer->id);

    /** @var AuthFactory $auth */
    $auth = $this->app->make(AuthFactory::class);
    expect($auth->guard()->id())->toBe($this->developer->id);

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    expect($session->get('auth.impersonating.original_user_id'))->toBeNull();
    expect($session->get('auth.impersonating.original_username'))->toBeNull();

    $alert = SystemAlert::query()->withoutGlobalScopes()->where('kind', 'auth.impersonation_ended')->first();
    expect($alert)->not->toBeNull();
    expect($alert?->severity)->toBe('warning');
});

it('returns not-allowed when ending a switch that is not active', function (): void {
    $this->actingAs($this->developer);

    /** @var EndImpersonationAction $end */
    $end = $this->app->make(EndImpersonationAction::class);
    $result = $end();

    expect($result->status)->toBe('not_allowed');
});

it('returns 404 for POST /impersonate as a non-developer', function (): void {
    $this->actingAs($this->partner)
        ->post('/impersonate', [
            'password' => 'partner-pass-phrase',
            'target_user_id' => $this->developer->id,
        ])
        ->assertNotFound();
});

it('redirects and ends the session for POST /impersonate/end while impersonating', function (): void {
    $this->actingAs($this->developer);

    /** @var ImpersonateUserAction $start */
    $start = $this->app->make(ImpersonateUserAction::class);
    $start($this->password, $this->partner->id);

    $this->post('/impersonate/end')->assertRedirect(route('dashboard'));

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    expect($session->get('auth.impersonating.original_user_id'))->toBeNull();
});
