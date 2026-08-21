<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;

beforeEach(function (): void {
    $this->userA = User::query()->create([
        'username' => 'oauth-user-a',
        'password' => 'password-a',
        'period_start_day' => 1,
    ]);
    $this->userB = User::query()->create([
        'username' => 'oauth-user-b',
        'password' => 'password-b',
        'period_start_day' => 1,
    ]);
});

it('a provider credential saved by user A is invisible to user B', function (): void {
    $repo = $this->app->make(OAuthSecretsRepository::class);

    $this->actingAs($this->userA);
    $repo->saveProviderClient('gmail', 'a-client-id', 'a-secret', 'http://127.0.0.1/a');
    expect($repo->hasProviderClient('gmail'))->toBeTrue();

    $this->actingAs($this->userB);
    expect($repo->hasProviderClient('gmail'))->toBeFalse();
    expect($repo->loadProviderClient('gmail'))->toBeNull();
});

it('users with the same provider see only their own credential', function (): void {
    $repo = $this->app->make(OAuthSecretsRepository::class);

    $this->actingAs($this->userA);
    $repo->saveProviderClient('gmail', 'a-id', 'a-secret', 'http://127.0.0.1/a');

    $this->actingAs($this->userB);
    $repo->saveProviderClient('gmail', 'b-id', 'b-secret', 'http://127.0.0.1/b');

    $this->actingAs($this->userA);
    expect($repo->loadProviderClient('gmail')['client_id'])->toBe('a-id');

    $this->actingAs($this->userB);
    expect($repo->loadProviderClient('gmail')['client_id'])->toBe('b-id');
});

it('an inbox saved by user A is not loadable by user B', function (): void {
    $repo = $this->app->make(OAuthSecretsRepository::class);

    $this->actingAs($this->userA);
    $repo->saveInboxRefreshToken(50, 'gmail', 'a@example.com', 'a-rt', 'scope', null);
    expect($repo->loadInbox(50))->not->toBeNull();

    $this->actingAs($this->userB);
    expect($repo->loadInbox(50))->toBeNull();
});
