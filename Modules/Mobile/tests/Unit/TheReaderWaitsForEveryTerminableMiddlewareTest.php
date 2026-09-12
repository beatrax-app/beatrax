<?php

declare(strict_types=1);

// AfterResponseMiddleware promises its work is paid after the response. Measured
// against both entrypoints with a terminable middleware busy-working 300 ms:
//
//   public/index.php          response bytes at 291.6 ms, tail 292.3 → 592.3 ms
//   Native\Mobile\Runtime     tail 47.3 → 347.3 ms, response bytes at 347.5 ms
//
// The promise holds in one and not the other, and the runtime is upstream's, so
// what this file pins is the shape the measurement found: dispatch terminates
// before it returns, and the shell has no byte to give the WebView until it has.

/** @return list<string> every vendor tree carrying the mobile plugin, from either composer root */
function heldResponseVendorRoots(): array
{
    $roots = [];

    foreach ([base_path('mobile-app/vendor'), base_path('vendor'), base_path('../vendor')] as $candidate) {
        if (is_dir($candidate.DIRECTORY_SEPARATOR.'nativephp'.DIRECTORY_SEPARATOR.'mobile')) {
            $roots[] = $candidate;
        }
    }

    return $roots;
}

function heldResponseSource(string $relative): ?string
{
    foreach (heldResponseVendorRoots() as $root) {
        $candidate = $root.DIRECTORY_SEPARATOR.$relative;

        if (is_file($candidate)) {
            return (string) file_get_contents($candidate);
        }
    }

    return null;
}

/** @return array<string, string> each shell bridge, keyed by the platform it builds */
function heldResponseBridges(): array
{
    $sources = [
        'android' => 'nativephp/mobile/resources/androidstudio/app/src/main/cpp/php_bridge.c',
        'ios' => 'nativephp/mobile/resources/xcode/Include/Bridge/PHP.c',
    ];

    $found = [];

    foreach ($sources as $platform => $relative) {
        $source = heldResponseSource($relative);

        if ($source !== null) {
            $found[$platform] = $source;
        }
    }

    return $found;
}

// The repository-root job installs no mobile-app/vendor, so finding nothing is
// an ordinary answer there. Asserted rather than assumed: a tree that HAS the
// plugin and yields no bridge is the plugin having moved it, and the two cases
// below would then pass over an empty set and agree with anything.
it('reads both shell bridges wherever the mobile plugin is installed', function (): void {
    expect(count(heldResponseBridges()))
        ->toBe(heldResponseVendorRoots() === [] ? 0 : 2);
});

it('terminates the request before it returns the response to the shell', function (): void {
    $runtime = heldResponseSource('nativephp/mobile/src/Runtime.php');

    if ($runtime === null) {
        expect(heldResponseVendorRoots())->toBe([]);

        return;
    }

    $terminate = strpos($runtime, 'static::$kernel->terminate($request, $response);');
    $return = strpos($runtime, 'return $response;');

    expect($terminate)->toBeInt()
        ->and($return)->toBeInt()
        ->and($terminate)->toBeLessThan($return);
});

// Two serialisations, not one. The eval body only starts writing the response
// once dispatch() has returned, and ub_write appends to a buffer the C layer
// reads after zend_eval_string — so there is no half-response to stream either.
it('has no byte of the response to hand the webview until the tail is done', function (): void {
    $bridges = heldResponseBridges();

    if ($bridges === []) {
        expect(heldResponseVendorRoots())->toBe([]);

        return;
    }

    foreach ($bridges as $platform => $bridge) {
        $dispatch = strpos($bridge, 'Runtime::dispatch(');
        $send = strpos($bridge, 'sendContent();');
        $eval = strpos($bridge, 'zend_eval_string(eval_code, NULL, "persistent_dispatch")');
        $collect = $eval === false ? false : strpos($bridge, 'get_collected_output()', $eval);

        expect($dispatch)->toBeInt(sprintf('%s no longer dispatches through the persistent runtime', $platform))
            ->and($send)->toBeInt(sprintf('%s no longer writes the response from the dispatch body', $platform))
            ->and($eval)->toBeInt(sprintf('%s no longer evaluates the dispatch body in one call', $platform))
            ->and($collect)->toBeInt(sprintf('%s no longer collects its output after the eval', $platform))
            ->and($dispatch)->toBeLessThan($send)
            ->and($eval)->toBeLessThan($collect);
    }
});
