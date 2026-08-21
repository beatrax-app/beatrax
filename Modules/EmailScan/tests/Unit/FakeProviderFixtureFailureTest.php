<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Modules\EmailScan\Internal\Clients\FakeGmailApiClient;
use Modules\EmailScan\Internal\Clients\FakeGraphApiClient;
use Modules\EmailScan\Internal\Clients\FixtureUnusableException;

// These clients back demo mode, so a missing fixture is a packaging fault: no
// live provider is involved and no retry helps. A demo that cannot find its
// own .eml must not read as a provider outage in the logs.

it('says the fixture root is missing rather than reporting a provider problem', function (): void {
    $client = new FakeGraphApiClient(new Filesystem, '/nonexistent/fixture/root');

    expect(fn () => $client->getRawMessage(1, 'paypal-001'))
        ->toThrow(FixtureUnusableException::class, 'eml root not found');
});

// The slug is the message id up to its first dash, so an id naming a sender
// the fixture set does not ship resolves to nothing — the message names the
// slug, because that is what a maintainer has to go and add.
it('names the slug it has no .eml for', function (): void {
    $client = new FakeGraphApiClient(new Filesystem);

    expect(fn () => $client->getRawMessage(1, 'nosuchvendor-001'))
        ->toThrow(FixtureUnusableException::class, 'nosuchvendor');
});

it('reports a fixture fault as a type distinct from a provider transport failure', function (): void {
    expect(is_subclass_of(FixtureUnusableException::class, RuntimeException::class))->toBeTrue();
});

function fakeGmailFixtureRoot(array $files): string
{
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-gmail-fixture-'.bin2hex(random_bytes(6));
    mkdir($root, 0700, true);
    foreach ($files as $name => $contents) {
        file_put_contents($root.DIRECTORY_SEPARATOR.$name, $contents);
    }

    return $root;
}

// Both Gmail failures are about the fixture file rather than the mailbox, and
// both are reached the same way, so one dataset covers them without repeating
// the temp-directory dance twice.
it('refuses a Gmail fixture it cannot read a message out of', function (string $json, string $needle): void {
    $root = fakeGmailFixtureRoot(['messages-get-raw-paypal.json' => $json]);
    $client = new FakeGmailApiClient(new Filesystem, $root);

    try {
        expect(fn () => $client->getRawMessage(1, 'paypal-001'))
            ->toThrow(FixtureUnusableException::class, $needle);
    } finally {
        array_map('unlink', (array) glob($root.'/*'));
        rmdir($root);
    }
})->with([
    // Names the message, because the fixture set is per-slug and the id is
    // what tells a maintainer which file to go and add the field to.
    'the raw field is absent' => ['{"id":"paypal-001"}', 'paypal-001'],
    // The fake decodes exactly as the real client does, so a payload that is
    // not base64url would otherwise surface as an empty message rather than
    // as the packaging error it is.
    'the raw payload is not base64url' => ['{"raw":"!!! not base64url !!!"}', 'Invalid base64url'],
]);
