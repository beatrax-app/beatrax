<?php

declare(strict_types=1);

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Internal\Http\Middleware\FirstUserOnlyMiddleware;
use Modules\Auth\Models\UserRecoveryCode;
use Modules\Auth\Public\Actions\SignupAction;
use Modules\Core\Models\User;
use Modules\Core\Public\Events\UserInstalled;
use Modules\Ledger\Models\Category;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

it('creates the first user with ten recovery codes and logs them in', function (): void {
    /** @var SignupAction $signup */
    $signup = $this->app->make(SignupAction::class);

    $result = $signup('alice', 'a-long-password-12chars');

    expect($result['user'])->toBeInstanceOf(User::class);
    expect($result['user']->is_developer)->toBeTrue();
    expect($result['user']->force_password_change_at_next_login)->toBeFalse();
    expect($result['codesPlain'])->toHaveCount(10);

    expect(User::query()->count())->toBe(1);
    expect(UserRecoveryCode::query()->where('user_id', $result['user']->id)->whereNull('used_at')->count())->toBe(10);

    /** @var AuthManager $auth */
    $auth = $this->app->make(AuthManager::class);
    expect($auth->guard()->check())->toBeTrue();
});

it('stores recovery codes bcrypt-hashed, never as plaintext', function (): void {
    /** @var SignupAction $signup */
    $signup = $this->app->make(SignupAction::class);

    $result = $signup('alice', 'a-long-password-12chars');

    /** @var Hasher $hasher */
    $hasher = $this->app->make(Hasher::class);

    $hashes = UserRecoveryCode::query()->where('user_id', $result['user']->id)->pluck('code_hash');

    foreach ($result['codesPlain'] as $plain) {
        expect($hashes)->not->toContain($plain);
    }
    expect($hasher->check($result['codesPlain'][0], $hashes[0]))->toBeTrue();
});

it('lowercases the username before storage', function (): void {
    /** @var SignupAction $signup */
    $signup = $this->app->make(SignupAction::class);

    $result = $signup('Alice', 'a-long-password-12chars');

    expect($result['user']->username)->toBe('alice');
});

it('stashes the plaintext codes in the session for the display ceremony', function (): void {
    /** @var SignupAction $signup */
    $signup = $this->app->make(SignupAction::class);

    $result = $signup('alice', 'a-long-password-12chars');

    expect(session('auth.signup.recovery_codes_plain'))->toBe($result['codesPlain']);
});

it('throws a ValidationException when a user already exists', function (): void {
    User::query()->create([
        'username' => 'existing',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);

    /** @var SignupAction $signup */
    $signup = $this->app->make(SignupAction::class);

    try {
        $signup('alice', 'a-long-password-12chars');
        $this->fail('expected a ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('signup');
    }

    expect(User::query()->count())->toBe(1);
});

it('rejects a second signup once the first has succeeded', function (): void {
    /** @var SignupAction $signup */
    $signup = $this->app->make(SignupAction::class);

    $signup('alice', 'a-long-password-12chars');

    expect(fn () => $signup('bob', 'another-long-password'))
        ->toThrow(ValidationException::class);

    expect(User::query()->count())->toBe(1);
});

it('rejects a password shorter than twelve characters', function (): void {
    /** @var SignupAction $signup */
    $signup = $this->app->make(SignupAction::class);

    try {
        $signup('alice', 'short');
        $this->fail('expected a ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('password');
        expect($e->errors()['password'][0])->toBe('Use at least 12 characters.');
    }

    expect(User::query()->count())->toBe(0);
});

it('generates ten distinct plaintext recovery codes', function (): void {
    /** @var SignupAction $signup */
    $signup = $this->app->make(SignupAction::class);

    $result = $signup('alice', 'a-long-password-12chars');

    expect($result['codesPlain'])->toHaveCount(10);
    expect(array_unique($result['codesPlain']))->toHaveCount(10);
});

it('rejects a second user_recovery_codes row carrying a duplicate code_hash', function (): void {
    $user = User::query()->create([
        'username' => 'alice',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);

    UserRecoveryCode::query()->create([
        'user_id' => $user->id,
        'code_hash' => 'identical-hash-value',
        'used_at' => null,
    ]);

    expect(fn () => UserRecoveryCode::query()->create([
        'user_id' => $user->id,
        'code_hash' => 'identical-hash-value',
        'used_at' => null,
    ]))->toThrow(QueryException::class);
});

it('passes the FirstUserOnlyMiddleware through on an empty database', function (): void {
    /** @var FirstUserOnlyMiddleware $middleware */
    $middleware = $this->app->make(FirstUserOnlyMiddleware::class);

    $response = $middleware->handle(Request::create('/signup'), static fn (): Response => new Response('ok'));

    expect($response->getContent())->toBe('ok');
});

it('throws a 404 from FirstUserOnlyMiddleware once a user exists', function (): void {
    User::query()->create([
        'username' => 'existing',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);

    /** @var FirstUserOnlyMiddleware $middleware */
    $middleware = $this->app->make(FirstUserOnlyMiddleware::class);

    expect(fn () => $middleware->handle(Request::create('/signup'), static fn (): Response => new Response('ok')))
        ->toThrow(NotFoundHttpException::class);
});

it('seeds the default category tree by dispatching UserInstalled after the user is created', function (): void {
    // The precondition, guarded: signup is the only thing that populates either
    // of these on a first launch.
    expect(User::query()->count())->toBe(0);
    expect(Category::withoutGlobalScopes()->whereNull('user_id')->count())->toBe(0);

    /** @var SignupAction $signup */
    $signup = $this->app->make(SignupAction::class);

    $result = $signup('alice', 'a-long-password-12chars');

    expect($result['user'])->toBeInstanceOf(User::class);

    // A lower bound so tweaking a leaf does not break this, plus one slug so a
    // listener that short-circuits after the event still fails.
    $sharedCategories = Category::withoutGlobalScopes()->whereNull('user_id');
    expect($sharedCategories->count())->toBeGreaterThanOrEqual(13);
    expect($sharedCategories->where('slug', 'subscriptions-streaming')->exists())->toBeTrue();
});

it('dispatches UserInstalled exactly once with the new users id', function (): void {
    /** @var list<int> $captured */
    $captured = [];

    // A live listener rather than Event::fake(), which would shadow the real
    // one and mask the seeding wiring the test above depends on.
    $this->app->make(Dispatcher::class)->listen(
        UserInstalled::class,
        function (UserInstalled $event) use (&$captured): void {
            $captured[] = $event->userId;
        },
    );

    /** @var SignupAction $signup */
    $signup = $this->app->make(SignupAction::class);

    $result = $signup('alice', 'a-long-password-12chars');

    expect($captured)->toBe([$result['user']->id]);
});
