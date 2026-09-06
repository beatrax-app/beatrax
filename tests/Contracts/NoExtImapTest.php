<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

// Mail access is provider-API-only because the removed extension is used
// nowhere; an IMAP library is a specification violation rather than a shortcut.

/**
 * The two ways a file DEPENDS on the extension: a gate on it being loaded, and
 * a call into it. Naming it is not depending on it -- DoctorCommand reports
 * `ext-imap` as an informational row precisely because the project works
 * whether or not the extension is there, and reading a mention as a dependency
 * would make that row impossible to write.
 *
 * @return list<string>
 */
function imapExtensionDependenciesIn(string $source): array
{
    $stripped = PatternScan::replace('#/\*.*?\*/|//[^\n]*#s', '', $source);
    $found = [];

    if (PatternScan::matches('/extension_loaded\s*\(\s*[\'"]imap[\'"]\s*\)/i', $stripped)) {
        $found[] = "extension_loaded('imap')";
    }

    foreach (PatternScan::all('/(?<![>:$\w])(imap_[a-z_]+)\s*\(/i', $stripped)[1] as $call) {
        $found[] = $call.'()';
    }

    return array_values(array_unique($found));
}

it('composer.json does not require ext-imap', function (): void {
    $raw = (string) file_get_contents(base_path('composer.json'));

    expect($raw)->not->toContain('"ext-imap"');

    $composer = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    expect($composer)->toBeArray('composer.json does not decode to an object.');

    /** @var array{require?: array<string, string>, require-dev?: array<string, string>} $composer */
    $require = array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []);
    expect($require)->not->toHaveKey('ext-imap');
});

it('ships no PHP that gates on the imap extension or calls into it', function (): void {
    $sources = RepoTree::files(RepoTree::PRODUCTION_PHP);

    // Read before the verdict: this walk used to shell out to grep and read the
    // hit list without the exit code, so a bad path answered 2, printed
    // nothing, and was indistinguishable from a clean tree. The floor sits far
    // under today's 6,681 files.
    expect(count($sources))->toBeGreaterThan(
        3000,
        'RepoTree returned '.count($sources).' shipped PHP files, which is too few to have read the tree.'
    );

    $offenders = [];

    foreach ($sources as $path) {
        foreach (imapExtensionDependenciesIn((string) file_get_contents($path)) as $dependency) {
            $offenders[] = str_replace(RepoTree::root().'/', '', $path).' — '.$dependency;
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These depend on an extension PHP 8.5 no longer ships and this product does not use:',
        ...$offenders,
        '',
        'Mail access is provider-API-only (Gmail API, Microsoft Graph). The inbox scan reads with',
        'the pure-PHP parser, which needs no extension at all.',
    ]));
});

it('composer.lock contains no webklex/php-imap, webklex/laravel-imap, or ddeboer/imap packages', function (): void {
    // composer.json's "conflict" block already hard-fails the install. This
    // grep catches what that cannot: a hand-edited composer.lock, or a forced
    // override that slipped past the resolver.
    $lockPath = base_path('composer.lock');

    // Asserted rather than skipped: the lockfile is committed, so a run that
    // cannot find one is reading somewhere this rule does not describe, and
    // waving it through is how a resolver override ships unread.
    expect(is_file($lockPath))->toBeTrue(
        $lockPath.' does not exist. This rule is about the resolved dependency set, and there is none to read.'
    );

    $contents = (string) file_get_contents($lockPath);
    $banned = [
        'webklex/laravel-imap',
        'webklex/php-imap',
        'ddeboer/imap',
    ];

    $hits = [];
    foreach ($banned as $package) {
        if (str_contains($contents, "\"{$package}\"")) {
            $hits[] = $package;
        }
    }

    expect($hits)->toBe(
        [],
        "composer.lock must not reference deprecated IMAP packages. Offenders:\n  ".implode("\n  ", $hits),
    );
});

// The tree holds none of what this looks for, so the reader is driven against a
// planted source. The near-miss is the site that really exists: the doctor row
// that names the extension to say the product does not need it.
it('tells a dependency on the extension from a report that names it', function (): void {
    $gate = 'extension_loaded'."('imap')";
    $call = 'imap_'.'open($mailbox, $user, $pass)';

    expect(imapExtensionDependenciesIn('<?php if ('.$gate.') { return true; }'))->toBe(["extension_loaded('imap')"])
        ->and(imapExtensionDependenciesIn('<?php $stream = '.$call.';'))->toBe(['imap_open()'])
        ->and(imapExtensionDependenciesIn("<?php \$loaded = in_array('imap', get_loaded_extensions(), true);"))->toBe([])
        ->and(imapExtensionDependenciesIn("<?php // ".$gate." was removed in 8.4\n"))->toBe([])
        ->and(imapExtensionDependenciesIn('<?php $this->'.$call.';'))->toBe([]);
});
