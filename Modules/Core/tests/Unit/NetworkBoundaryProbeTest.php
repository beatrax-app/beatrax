<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Modules\Core\Internal\Console\Probes\NetworkBoundaryProbe;
use Modules\Core\Internal\Console\Probes\ProbeSeverity;
use Modules\Core\Internal\Support\NetworkBoundary;

// The health endpoint carries the state word and nothing else, so this probe is
// the only place an operator can read which interfaces were actually taken,
// which entries were thrown away, and whether APP_URL agrees with the widening.

function networkBoundaryProbe(string $servedInterfaces, string $appUrl = 'https://beatrax.test'): NetworkBoundaryProbe
{
    return new NetworkBoundaryProbe(new NetworkBoundary(new Repository([
        'app' => ['url' => $appUrl],
        'selfhost' => ['served_interfaces' => $servedInterfaces],
    ])));
}

it('says the boundary is closed on an install that recorded nothing', function (): void {
    $result = networkBoundaryProbe('')->run();

    expect($result->severity)->toBe(ProbeSeverity::Ok->value);
    expect($result->message)->toBe('Loopback only. Every non-loopback request is refused with not-found.');
    expect($result->metadata['state'])->toBe('loopback');
    expect($result->metadata['served_interfaces'])->toBe('');
});

it('names the interfaces taken and the host they are served under', function (): void {
    $result = networkBoundaryProbe('192.168.1.50, 10.0.0.4', 'https://beatrax.example.com')->run();

    expect($result->severity)->toBe(ProbeSeverity::Ok->value);
    expect($result->message)->toContain('192.168.1.50, 10.0.0.4');
    expect($result->message)->toContain('beatrax.example.com');
    expect($result->metadata['state'])->toBe('widened');
    expect($result->metadata['served_interfaces'])->toBe('192.168.1.50 10.0.0.4');
});

// The entry that reads as honoured but changed nothing is the one an operator
// would otherwise chase for an afternoon.
it('warns about an entry it threw away, and names it back', function (): void {
    $result = networkBoundaryProbe('0.0.0.0')->run();

    expect($result->severity)->toBe(ProbeSeverity::Warning->value);
    expect($result->message)->toContain('0.0.0.0');
    expect($result->message)->toContain('Ignored 1 entry');
    expect($result->metadata['refused_interfaces'])->toBe('0.0.0.0');
    expect($result->metadata['state'])->toBe('loopback');
});

it('counts more than one thrown-away entry in the plural', function (): void {
    $result = networkBoundaryProbe('0.0.0.0, 192.168.1.0/24')->run();

    expect($result->severity)->toBe(ProbeSeverity::Warning->value);
    expect($result->message)->toContain('Ignored 2 entries');
    expect($result->metadata['refused_interfaces'])->toBe('0.0.0.0 192.168.1.0/24');
});

// Widened with APP_URL still on localhost is the trap: the interface record
// alone gets a FrankenPHP or `artisan serve` install nowhere, because those
// publish no bind address and the recorded host is what stands in for one.
it('warns when the widening is recorded but APP_URL names only loopback', function (): void {
    $result = networkBoundaryProbe('192.168.1.50', 'http://localhost:8000')->run();

    expect($result->severity)->toBe(ProbeSeverity::Warning->value);
    expect($result->message)->toContain('APP_URL names no host past loopback');
    expect($result->message)->toContain('192.168.1.50');
    expect($result->metadata['state'])->toBe('widened');
});

it('does not call a loopback-only record a widening', function (): void {
    $result = networkBoundaryProbe('127.0.0.1')->run();

    expect($result->severity)->toBe(ProbeSeverity::Ok->value);
    expect($result->metadata['state'])->toBe('loopback');
});

it('labels itself the way the doctor table prints it', function (): void {
    expect(networkBoundaryProbe('')->label())->toBe('Network boundary');
});
