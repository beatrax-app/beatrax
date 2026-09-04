<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// The product's position is that a ledger stays on the machines its owner
// controls, and mail is the one capability that quietly contradicts it: a
// single Mailable and a configured transport would send a reader's financial
// detail to a relay nobody chose. Today none exists — no Mailable subclass, no
// send site, and the only mail package in the tree is a PARSER the inbox scan
// reads with. What was missing is anything that keeps it that way.

/** @return list<string> every PHP source file the shells ship, tests excluded */
function outboundMailScannedSources(): array
{
    $found = [];

    foreach (['app', 'Modules'] as $directory) {
        $root = base_path($directory);

        if (! is_dir($root)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();

            if (! $file->isFile() || ! str_ends_with($path, '.php')) {
                continue;
            }

            // A test may name a transport it asserts is absent, and a fixture
            // may hold a whole message. Neither ships.
            if (str_contains($path, '/tests/')) {
                continue;
            }

            $found[] = $path;
        }
    }

    return $found;
}

function outboundMailRelative(string $path): string
{
    return str_replace(base_path().'/', '', $path);
}

it('ships no class that composes a message to send', function (): void {
    $sources = outboundMailScannedSources();

    // Counted first: a walk that resolved nothing would report a clean tree,
    // which is the same answer a clean tree gives.
    expect($sources)->not->toBeEmpty();

    $composers = [];

    foreach ($sources as $path) {
        $source = (string) file_get_contents($path);

        if (PatternScan::matches('/\bextends\s+Mailable\b/', $source)
            || PatternScan::matches('/Illuminate\\\\(?:Mail\\\\Mailable|Contracts\\\\Mail\\\\Mailable)/', $source)) {
            $composers[] = outboundMailRelative($path);
        }
    }

    expect($composers)->toBe([], 'these compose mail to send: '.implode(', ', $composers));
});

it('ships no call that hands a message to a transport', function (): void {
    $senders = [];

    foreach (outboundMailScannedSources() as $path) {
        $source = (string) file_get_contents($path);

        // The facade's sending surface. Mail::mailer() is included because it
        // is how a caller reaches a transport other than the configured one.
        if (PatternScan::matches('/\bMail::(?:send|to|bcc|cc|raw|html|plain|queue|later|mailer)\s*\(/', $source)) {
            $senders[] = outboundMailRelative($path);
        }
    }

    expect($senders)->toBe([], 'these send mail: '.implode(', ', $senders));
});

// Not published today, so the framework's own default applies. If it is ever
// published, the transport it falls back to is what a build with no MAIL_MAILER
// in its environment would use -- which is every shipped bundle, because the
// packager strips the key it does not need.
it('falls back to a transport that reaches no network, if it configures mail at all', function (): void {
    $config = base_path('config/mail.php');

    if (! is_file($config)) {
        expect(is_file($config))->toBeFalse();

        return;
    }

    $default = PatternScan::first("/'default'\s*=>\s*env\(\s*'MAIL_MAILER'\s*,\s*'([a-z]+)'/", (string) file_get_contents($config));

    expect($default)->not->toBe([])
        ->and($default[1])->toBeIn(['log', 'array', 'failover']);
});
