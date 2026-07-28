<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\Guard;
use Modules\Core\Models\User;
use Modules\Core\Public\Exceptions\NotAuthenticatedException;
use Modules\Core\Public\Services\CurrentUserService;

it('returns the authenticated user via the configured guard', function (): void {
    $user = new User(['username' => 'x', 'period_start_day' => 7]);
    $user->id = 42;
    $user->exists = true;

    $guard = $this->createStub(Guard::class);
    $guard->method('user')->willReturn($user);

    $factory = $this->createStub(AuthFactory::class);
    $factory->method('guard')->willReturn($guard);

    $svc = new CurrentUserService($factory);

    expect($svc->isAuthenticated())->toBeTrue();
    expect($svc->id())->toBe(42);
    expect($svc->user())->toBe($user);
    expect($svc->periodStartDay())->toBe(7);
});

it('throws NotAuthenticatedException when the guard has no user', function (): void {
    $guard = $this->createStub(Guard::class);
    $guard->method('user')->willReturn(null);

    $factory = $this->createStub(AuthFactory::class);
    $factory->method('guard')->willReturn($guard);

    $svc = new CurrentUserService($factory);

    expect($svc->isAuthenticated())->toBeFalse();
    expect(fn () => $svc->id())->toThrow(NotAuthenticatedException::class);
    expect(fn () => $svc->user())->toThrow(NotAuthenticatedException::class);
});

it('defaults period_start_day to 1 when the user has not set one', function (): void {
    $user = new User(['username' => 'x']);
    $user->id = 1;
    $user->exists = true;

    $guard = $this->createStub(Guard::class);
    $guard->method('user')->willReturn($user);

    $factory = $this->createStub(AuthFactory::class);
    $factory->method('guard')->willReturn($guard);

    $svc = new CurrentUserService($factory);
    expect($svc->periodStartDay())->toBe(1);
});
