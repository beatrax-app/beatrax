<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\Application;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Exceptions\NotAuthenticatedException;
use Modules\Core\Public\Scopes\UserScope;

/*
 * UserScope must fail closed on a web request with no authenticated user: a
 * per-user table is constrained to match nothing rather than leaking every
 * user's rows. The console keeps its historical unscoped behaviour, which is
 * why the rest of the suite (running in the console) is unaffected.
 */

it('constrains a web query to nothing when no user is authenticated', function (): void {
    $currentUser = Mockery::mock(CurrentUser::class);
    $currentUser->shouldReceive('id')->andThrow(new NotAuthenticatedException('no user'));

    $app = Mockery::mock(Application::class);
    $app->shouldReceive('runningInConsole')->andReturnFalse();

    $model = new SystemAlert;
    $builder = $model->newQueryWithoutScopes();

    (new UserScope($currentUser, $app))->apply($builder, $model);

    expect($builder->toSql())->toContain('1 = 0');
});

it('leaves a console query unscoped when no user is authenticated', function (): void {
    $currentUser = Mockery::mock(CurrentUser::class);
    $currentUser->shouldReceive('id')->andThrow(new NotAuthenticatedException('no user'));

    $app = Mockery::mock(Application::class);
    $app->shouldReceive('runningInConsole')->andReturnTrue();

    $model = new SystemAlert;
    $builder = $model->newQueryWithoutScopes();

    (new UserScope($currentUser, $app))->apply($builder, $model);

    expect($builder->toSql())->not->toContain('1 = 0');
});
