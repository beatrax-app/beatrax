<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Modules\Core\Models\User;
use Modules\Mobile\Internal\Boot\SchemaCompletionMarker;
use Modules\Mobile\Internal\Http\Middleware\MobileEnsureDatabaseReady;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

uses(RefreshDatabase::class);

// A first launch whose migration run died partway still created `users` —
// migration two of a hundred and ninety — so isFreshInstall() answered true
// and the welcome screen opened over fifteen tables of a hundred and two.
// Every tap after that was a 500 and the only trace was a log line reading
// "failed non-fatally". SQLite reports supportsSchemaTransactions() false, so
// the half-applied run cannot be rolled back either: the device stays that way.
afterEach(fn () => SchemaCompletionMarker::clear());

function throughDatabaseGate(string $routeName): HttpResponse
{
    $request = Request::create('/mobile/anything');
    $request->setRouteResolver(fn () => new Route('GET', '/mobile/anything', ['as' => $routeName]));

    /** @var MobileEnsureDatabaseReady $middleware */
    $middleware = app(MobileEnsureDatabaseReady::class);

    return $middleware->handle($request, fn () => new Response('reached the app'));
}

it('sends a half-built install to the screen that explains it', function (): void {
    SchemaCompletionMarker::raise();

    $response = throughDatabaseGate('transactions.index');

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toBe(route('mobile.database-incomplete'));
});

it('lets its own screen render, or the reader would bounce forever', function (): void {
    SchemaCompletionMarker::raise();

    expect(throughDatabaseGate('mobile.database-incomplete')->getContent())->toBe('reached the app');
});

it('is out of the way once the schema is whole', function (): void {
    SchemaCompletionMarker::clear();

    // No marker and a user present: the ordinary path, untouched.
    User::query()->create([
        'username' => 'schema-probe',
        'password' => bcrypt('schema-probe-pass'),
        'period_start_day' => 1,
    ]);

    expect(throughDatabaseGate('transactions.index')->getContent())->toBe('reached the app');
});

// The routes below are exempt from the gate because they run before any user
// account exists. Running before a user exists is a different thing from
// running before the TABLES do, and the marker check used to sit behind that
// exemption: the welcome screen opened over a half-built schema exactly as it
// did on 2026-08-29, and signup was one tap away.
it('does not open the welcome screen over a schema that stopped halfway', function (): void {
    SchemaCompletionMarker::raise();

    $response = throughDatabaseGate('mobile.welcome');

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toBe(route('mobile.database-incomplete'));
});

it('does not let signup be reached over one either', function (): void {
    SchemaCompletionMarker::raise();

    expect(throughDatabaseGate('signup')->getStatusCode())->toBe(302);
});

it('keeps the retry round trip open, which is the only way out', function (): void {
    SchemaCompletionMarker::raise();

    expect(throughDatabaseGate('livewire.update')->getContent())->toBe('reached the app');
});

it('still serves the brand artefacts the lock layout renders', function (): void {
    SchemaCompletionMarker::raise();

    expect(throughDatabaseGate('app.icon')->getContent())->toBe('reached the app');
});

// runPendingMigrations() raises the marker in its own finally, but the boot
// hook calls three things before it — the container resolution, the plugin view
// paths, and the pending check, which queries the database it is asking about.
// Any of those throwing landed in the same catch, and the app opened with
// nothing but a log line saying so.
it('marks the schema incomplete when the boot hook catches anything at all', function (): void {
    $source = (string) file_get_contents(base_path('mobile-app/bootstrap/app.php'));

    expect($source)->toContain('use Modules\Mobile\Internal\Boot\SchemaCompletionMarker;');
    expect($source)->toContain("SchemaCompletionMarker::raise();\n\n            \$app->make(LoggerInterface::class)->error(\n                'Mobile first-launch migrate-on-launch failed non-fatally.',");
    // repo-root-only: the path resolves against the repo root, so from the
    // mobile-app root this looks for mobile-app/mobile-app/bootstrap/app.php.
})->group('repo-root-only');
