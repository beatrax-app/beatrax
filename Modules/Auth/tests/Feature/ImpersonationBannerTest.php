<?php

declare(strict_types=1);

use Illuminate\Contracts\Hashing\Hasher;
use Modules\Core\Models\User;

/*
 * Feature coverage for the persistent profile-switch banner. While a
 * switch is active, ImpersonationBannerMiddleware shares the partner
 * username and the application layout paints the amber banner on every
 * authenticated page; with no switch active the banner is absent.
 */

beforeEach(function (): void {
    /** @var Hasher $hasher */
    $hasher = $this->app->make(Hasher::class);

    $this->developer = User::query()->create([
        'username' => 'owner',
        'password' => $hasher->make('owner-pass-phrase'),
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

it('renders the amber banner with the partner username on an authenticated page while impersonating', function (): void {
    // Act as the partner with the impersonation session keys set — this
    // is exactly the state ImpersonateUserAction leaves behind.
    $response = $this->actingAs($this->partner)
        ->withSession([
            'auth.impersonating.original_user_id' => $this->developer->id,
            'auth.impersonating.original_username' => 'owner',
        ])
        ->get('/transactions');

    $response->assertOk();
    $response->assertSee('Acting as');
    $response->assertSee('partner');
    $response->assertSee('Return to self');
});

it('does not render the banner on an authenticated page when not impersonating', function (): void {
    $response = $this->actingAs($this->partner)->get('/transactions');

    $response->assertOk();
    $response->assertDontSee('Acting as');
    $response->assertDontSee('Return to self');
});
