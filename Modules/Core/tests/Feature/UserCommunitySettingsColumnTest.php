<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\User;

it('adds the community_settings JSON column to users', function (): void {
    expect(Schema::hasColumn('users', 'community_settings'))->toBeTrue();
});

it('defaults to null for a freshly-created user', function (): void {
    $user = User::create([
        'username' => 'community-default',
        'password' => 'pw',
        'period_start_day' => 1,
    ]);
    $user->refresh();

    expect($user->community_settings)->toBeNull();
});

it('round-trips a per-user settings array through the Eloquent cast', function (): void {
    $user = User::create([
        'username' => 'community-roundtrip',
        'password' => 'pw',
        'period_start_day' => 1,
    ]);
    $user->community_settings = [
        'useSharedList' => true,
        'offerToContribute' => false,
        'updateOnAppUpdates' => false,
    ];
    $user->save();
    $user->refresh();

    expect($user->community_settings)->toBeArray();
    expect($user->community_settings)->toHaveKey('useSharedList', true);
    expect($user->community_settings)->toHaveKey('offerToContribute', false);
    expect($user->community_settings)->toHaveKey('updateOnAppUpdates', false);
});

it('allows updating individual keys without resetting unrelated ones', function (): void {
    $user = User::create([
        'username' => 'community-update',
        'password' => 'pw',
        'period_start_day' => 1,
        'community_settings' => [
            'useSharedList' => true,
            'offerToContribute' => true,
        ],
    ]);
    $user->refresh();

    $settings = $user->community_settings ?? [];
    $settings['offerToContribute'] = false;
    $user->community_settings = $settings;
    $user->save();
    $user->refresh();

    expect($user->community_settings['useSharedList'])->toBeTrue();
    expect($user->community_settings['offerToContribute'])->toBeFalse();
});
