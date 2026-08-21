<?php

declare(strict_types=1);

// AllowOriginAcceptor compares the request's Origin header against its list with
// in_array(), so ['*'] matched only the literal string and every upgrade came
// back "403 Origin forbidden" — a native peer sends no Origin at all. Noise is
// the authentication gate here, not a browser header.

it('does not gate the sync listener on an Origin header', function (): void {
    $path = dirname(__DIR__, 2).'/Commands/SyncServeCommand.php';

    expect(is_file($path))->toBeTrue($path);

    $source = (string) file_get_contents($path);

    // Matches the construction, not the comment explaining why it is gone.
    expect($source)->not->toContain('new AllowOriginAcceptor')
        ->and($source)->toContain('new Rfc6455Acceptor');
});
