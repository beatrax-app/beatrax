<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\UrlGenerator;
use Modules\Core\Models\User;
use Modules\Mobile\Internal\Boot\MobileFirstLaunchBootstrap;
use Modules\Mobile\Internal\Boot\SchemaCompletionMarker;
use Modules\Mobile\Internal\Http\Middleware\MobileEnsureDatabaseReady;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

// The marker is the only thing standing between a half-built schema and a
// welcome screen opened over it. raise() returned void and swallowed both the
// mkdir and the write, so a marker that was never written read back as
// "schema complete" — the one state it exists to deny.

afterEach(function (): void {
    $dir = dirname(SchemaCompletionMarker::path());
    if (is_dir($dir)) {
        chmod($dir, 0755);
    }
    SchemaCompletionMarker::clear();
});

function markerWriteBlocked(callable $body): mixed
{
    $dir = dirname(SchemaCompletionMarker::path());
    @mkdir($dir, 0755, true);
    @unlink(SchemaCompletionMarker::path());
    chmod($dir, 0500);

    try {
        return $body();
    } finally {
        chmod($dir, 0755);
    }
}

it('reports that the marker it could not write is not on disk', function (): void {
    $durable = markerWriteBlocked(static fn () => SchemaCompletionMarker::raise());

    expect($durable)->toBeFalse('raise() reported nothing at all about a marker that was never written');
    expect(file_exists(SchemaCompletionMarker::path()))->toBeFalse();
});

it('still refuses this launch when the marker could not reach the disk', function (): void {
    markerWriteBlocked(static fn () => SchemaCompletionMarker::raise());

    expect(SchemaCompletionMarker::isRaised())->toBeTrue(
        'a marker that could not be written read as "schema complete", which is what opens the welcome screen over a half-built database',
    );
});

it('sends the request to the incomplete-schema screen on a marker only memory holds', function (): void {
    User::query()->create([
        'username' => 'marker-refusal-user',
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    markerWriteBlocked(static fn () => SchemaCompletionMarker::raise());

    $request = Request::create('/dashboard', 'GET');
    $request->setRouteResolver(static fn (): RoutingRoute => new RoutingRoute(['GET'], '/dashboard', ['as' => 'dashboard']));

    $middleware = new MobileEnsureDatabaseReady(
        app(MobileFirstLaunchBootstrap::class),
        app(UrlGenerator::class),
    );

    $response = $middleware->handle($request, static fn (): Response => new Response('', 204));

    expect($response)->toBeInstanceOf(RedirectResponse::class);
    expect($response->headers->get('Location'))->toContain('database-incomplete');
});

it('clears the in-memory refusal once the schema completes', function (): void {
    markerWriteBlocked(static fn () => SchemaCompletionMarker::raise());

    SchemaCompletionMarker::clear();

    expect(SchemaCompletionMarker::isRaised())->toBeFalse();
});
