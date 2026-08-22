<?php

declare(strict_types=1);

use Illuminate\Auth\AuthManager;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Mobile\Internal\Http\Middleware\ForgetStaleSessionBetweenRequests;

uses(RefreshDatabase::class);

// The mobile runtime boots once per process, so `session.store` is one object
// for the life of the app. Store::save() writes the attributes out and leaves
// them in memory, and Store::start() array_replace()s the incoming id's row
// over them rather than replacing them.

function mobileSessionId(): string
{
    return Str::random(40);
}

function mobileAuthSessionKey(): string
{
    /** @var SessionGuard $guard */
    $guard = app(AuthManager::class)->guard();

    return $guard->getName();
}

/**
 * @param  Closure(Session): void  $inside
 * @param  list<class-string>  $before
 */
function mobileRequestCycle(?string $cookieId, Closure $inside, array $before = []): void
{
    $request = Request::create('/mobile-request-cycle', 'GET');

    if ($cookieId !== null) {
        $request->cookies->set(config('session.cookie'), $cookieId);
    }

    $terminal = static function (Request $request) use ($inside): Response {
        $inside($request->session());

        return new Response('ok');
    };

    $pipeline = static fn (Request $request): Response => app(StartSession::class)->handle($request, $terminal);

    foreach (array_reverse($before) as $middleware) {
        $outer = $pipeline;
        $pipeline = static fn (Request $request): Response => app($middleware)->handle($request, $outer);
    }

    $pipeline($request);
}

it('serves a new session id everything the last request left behind', function (): void {
    $signedIn = mobileSessionId();

    mobileRequestCycle($signedIn, static function (Session $session): void {
        $session->put(mobileAuthSessionKey(), 1);
        app(AppLockKeyService::class)->admitDataKey($session, 'the-data-key');
    });

    // Proof the defect is real and not an artefact of the fix: a session id
    // storage has never seen, served by the same process, arrives authenticated
    // and holding the data key, because array_replace() had nothing to replace.
    mobileRequestCycle(mobileSessionId(), static function (Session $session): void {
        expect($session->get(mobileAuthSessionKey()))->toBe(1)
            ->and(app(AppLockKeyService::class)->release($session))->toBe('the-data-key');
    });
});

it('hands a new session id nothing once the stale copy is dropped', function (): void {
    $signedIn = mobileSessionId();

    mobileRequestCycle($signedIn, static function (Session $session): void {
        $session->put(mobileAuthSessionKey(), 1);
        app(AppLockKeyService::class)->admitDataKey($session, 'the-data-key');
    }, [ForgetStaleSessionBetweenRequests::class]);

    mobileRequestCycle(mobileSessionId(), static function (Session $session): void {
        expect($session->has(mobileAuthSessionKey()))->toBeFalse()
            ->and(app(AppLockKeyService::class)->release($session))->toBeNull();
    }, [ForgetStaleSessionBetweenRequests::class]);
});

// ResetPasswordAction deletes every `sessions` row for the user and
// ChangePasswordPage every row but the caller's, both to sever a session after
// a suspected compromise. Severing the row is all the app can do; whether the
// session actually ends is decided here.
it('does not rebuild a session whose row the app deleted', function (): void {
    $live = mobileSessionId();

    mobileRequestCycle($live, static function (Session $session): void {
        $session->put(mobileAuthSessionKey(), 1);
        app(AppLockKeyService::class)->admitDataKey($session, 'the-data-key');
    }, [ForgetStaleSessionBetweenRequests::class]);

    expect(DB::table('sessions')->where('id', $live)->count())->toBe(1);

    DB::table('sessions')->where('id', $live)->delete();

    mobileRequestCycle($live, static function (Session $session): void {
        expect($session->has(mobileAuthSessionKey()))->toBeFalse()
            ->and(app(AppLockKeyService::class)->release($session))->toBeNull();
    }, [ForgetStaleSessionBetweenRequests::class]);
});

// Unconditional, this middleware would empty every session seeded before a
// request — which is how withSession() and the rest of the harness sign a
// caller in — and answer the mobile root's own suite as a guest, the same trap
// the guard drop next to it fell into.
it('leaves a session the caller filled before the request', function (): void {
    $session = app(Session::class);
    $session->start();
    $session->put('seeded-before-the-request', 'kept');

    mobileRequestCycle(null, static function (Session $session): void {
        expect($session->get('seeded-before-the-request'))->toBe('kept');
    }, [ForgetStaleSessionBetweenRequests::class]);
});

// regenerate() mints a new id and keeps the attributes on purpose — that is
// what carries a signed-in session across the id rotation login performs. It
// happens inside a started session, after this middleware has already run.
it('leaves a session regenerated inside the request intact', function (): void {
    mobileRequestCycle(mobileSessionId(), static function (Session $session): void {
        $session->put('survives-the-rotation', 'kept');
        $before = $session->getId();

        $session->regenerate();

        expect($session->getId())->not->toBe($before)
            ->and($session->get('survives-the-rotation'))->toBe('kept');
    }, [ForgetStaleSessionBetweenRequests::class]);
});

// forgetGuards() rebuilds the session driver, and rebuilding registers a
// rebound callback the container never prunes. Emptying the store the process
// already holds is what keeps that list from growing.
it('empties the session store rather than replacing it', function (): void {
    $before = app(Session::class);

    mobileRequestCycle(mobileSessionId(), static function (Session $session): void {
        $session->put('anything', true);
    }, [ForgetStaleSessionBetweenRequests::class]);

    expect(app(Session::class))->toBe($before);
});

it('runs after the guard drop it depends on', function (): void {
    $bootstrap = (string) file_get_contents(
        is_file(base_path('mobile-app/bootstrap/app.php'))
            ? base_path('mobile-app/bootstrap/app.php')
            : base_path('bootstrap/app.php')
    );

    $stale = strpos($bootstrap, 'prepend(ForgetStaleSessionBetweenRequests::class)');
    $guards = strpos($bootstrap, 'prepend(ForgetGuardsBetweenRequests::class)');

    // prepend() reverses, so prepended FIRST is run LAST: the guard drop reads
    // the session the previous request left in memory to decide whether the
    // user can be resolved again, and this is what empties it.
    expect($stale)->toBeInt()->and($guards)->toBeInt()->and($stale)->toBeLessThan($guards);
});
