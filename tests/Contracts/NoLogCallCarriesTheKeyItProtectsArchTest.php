<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// A group-data-key epoch is the whole household's history in thirty-two bytes,
// and a log file is the one place in the product with no lock on it at all. The
// epoch id, the device it came from and the reason it was refused are all safe
// to write down; the bytes themselves are not, in any context and at any level.

/** @return list<string> every PHP file the shells ship, tests excluded */
function keyMaterialLogSources(): array
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

            if ($file->isFile() && str_ends_with($path, '.php') && ! str_contains($path, '/tests/')) {
                $found[] = $path;
            }
        }
    }

    sort($found);

    return $found;
}

// A statement, not a balanced argument list: PHP has no `;` inside an
// expression, so the first one after the call ends it, and a `;` inside a
// message literal only shortens the span this reads.
function keyMaterialLogStatementAt(string $source, int $offset): string
{
    $end = strpos($source, ';', $offset);

    return substr($source, $offset, ($end === false ? min(strlen($source), $offset + 1500) : $end) - $offset);
}

it('hands no key material to a logger, at any level and in any context', function (): void {
    $sources = keyMaterialLogSources();

    expect($sources)->not->toBeEmpty();

    $levels = 'emergency|alert|critical|error|warning|notice|info|debug';
    $sink = '/(?:\$this->log(?:ger)?|\$\w*[Ll]og(?:ger)?|Log|logger\(\))\s*(?:->|::)\s*(?:'.$levels.')\s*\(/';

    // A variable or an array key that names key material, or a read of the
    // property that holds it. Prose in a message says nothing on its own —
    // "wrapped_key_b64 is not valid base64" is a reason, not a key. An epoch
    // is barred as a whole object and allowed by its id, which is the half of
    // it that identifies the key rather than being it.
    $named = [
        '/\$\w*(?:[Kk]ey[Hh]ex|[Rr]aw\w*[Kk]ey|raw_key|kek|[Pp]assphrase|(?i:secretkey)|secret_key'
            .'|[Dd]ata[Kk]ey|data_key|[Ww]rapped(?:Bin|Key)|[Bb]lind[Ii]ndex[Kk]ey|[Kk]eyring)\b/',
        '/\$\w*[Ee]poch\b(?!->epoch(?:Id|_id))/',
        '/[\'"](?:key_hex|keyHex|raw_key|rawKey|kek|passphrase|data_key|dataKey|secret_key|secretKey'
            .'|wrapped_key_b64|wrappedKey|blind_index_key|blindIndexKey|keyring)[\'"]\s*=>/i',
        '/->(?:keyHex|rawKey|wrappedBin|x25519SecretKeyHex|ed25519SecretKeyHex|blindIndexKeyHex)\b/',
    ];

    $sinks = 0;
    $offenders = [];

    foreach ($sources as $path) {
        $stripped = PatternScan::replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($path));

        foreach (PatternScan::allWithOffsets($sink, $stripped)[0] ?? [] as $hit) {
            $sinks++;
            $statement = keyMaterialLogStatementAt($stripped, (int) $hit[1]);

            foreach ($named as $pattern) {
                if (PatternScan::matches($pattern, $statement)) {
                    $offenders[] = str_replace(base_path().'/', '', $path).': '.trim(PatternScan::replace('/\s+/', ' ', $statement));
                }
            }
        }
    }

    expect($sinks)->toBeGreaterThan(
        200,
        'the walk found almost no log call, and a walk that reads nothing reports the same clean tree a clean tree does',
    );

    expect($offenders)->toBe([], 'a log line is written in the clear, kept forever, and copied into a bug report. '
        .'Name the epoch, the device and the reason — never the bytes, the passphrase, or the object holding them: '
        .implode(' | ', $offenders));
});
