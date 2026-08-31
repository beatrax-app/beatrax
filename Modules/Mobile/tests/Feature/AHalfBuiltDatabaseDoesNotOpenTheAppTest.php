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
