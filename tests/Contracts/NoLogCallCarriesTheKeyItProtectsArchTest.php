<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

// A group-data-key epoch is the whole household's history in thirty-two bytes,
// and a log file is the one place in the product with no lock on it at all. The
// epoch id, the device it came from and the reason it was refused are all safe
// to write down; the bytes themselves are not, in any context and at any level.

const KEY_MATERIAL_LOG_SINK = '/(?:\$this->log(?:ger)?|\$\w*[Ll]og(?:ger)?|Log|logger\(\))\s*(?:->|::)\s*'
    .'(?:emergency|alert|critical|error|warning|notice|info|debug)\s*\(/';

// A variable or an array key that names key material, or a read of the
// property that holds it. Prose in a message says nothing on its own —
// "wrapped_key_b64 is not valid base64" is a reason, not a key. An epoch
// is barred as a whole object and allowed by its id, which is the half of
// it that identifies the key rather than being it.
const KEY_MATERIAL_NAMES = [
    '/\$\w*(?:[Kk]ey[Hh]ex|[Rr]aw\w*[Kk]ey|raw_key|kek|[Pp]assphrase|(?i:secretkey)|secret_key'
        .'|[Dd]ata[Kk]ey|data_key|[Ww]rapped(?:Bin|Key)|[Bb]lind[Ii]ndex[Kk]ey|[Kk]eyring)\b/',
    '/\$\w*[Ee]poch\b(?!->epoch(?:Id|_id))/',
    '/[\'"](?:key_hex|keyHex|raw_key|rawKey|kek|passphrase|data_key|dataKey|secret_key|secretKey'
        .'|wrapped_key_b64|wrappedKey|blind_index_key|blindIndexKey|keyring)[\'"]\s*=>/i',
    '/->(?:keyHex|rawKey|wrappedBin|x25519SecretKeyHex|ed25519SecretKeyHex|blindIndexKeyHex)\b/',
];

// A statement, not a balanced argument list: PHP has no `;` inside an
// expression, so the first one after the call ends it, and a `;` inside a
// message literal only shortens the span this reads.
function keyMaterialLogStatementAt(string $source, int $offset): string
{
    $end = strpos($source, ';', $offset);

    return substr($source, $offset, ($end === false ? min(strlen($source), $offset + 1500) : $end) - $offset);
}

/**
 * The sink count travels with the verdict so the walk's denominator comes off
 * the same function the reader below is driven through, rather than off a
 * second copy of the pattern.
 *
 * @return array{sinks: int, offenders: list<string>}
 */
function keyMaterialLogReadOf(string $source): array
{
    $stripped = PatternScan::replace('#/\*.*?\*/|//[^\n]*#s', '', $source);
    $sinks = 0;
    $offenders = [];

    foreach (PatternScan::allWithOffsets(KEY_MATERIAL_LOG_SINK, $stripped)[0] ?? [] as $hit) {
        $sinks++;
        $statement = keyMaterialLogStatementAt($stripped, (int) $hit[1]);

        foreach (KEY_MATERIAL_NAMES as $pattern) {
            if (PatternScan::matches($pattern, $statement)) {
                $offenders[] = trim(PatternScan::replace('/\s+/', ' ', $statement));
            }
        }
    }

    return ['sinks' => $sinks, 'offenders' => $offenders];
}

it('hands no key material to a logger, at any level and in any context', function (): void {
    // Every root that ships, not app/ and Modules/ alone: a build script logs
    // to the same file, and "in any context" is a claim about the tree rather
    // than about two directories of it.
    $sources = RepoTree::files(RepoTree::PRODUCTION_PHP);

    expect(count($sources))->toBeGreaterThan(
        3000,
        'RepoTree returned '.count($sources).' shipped PHP files, which is too few to have read the tree.'
    );

    $sinks = 0;
    $offenders = [];

    foreach ($sources as $path) {
        $read = keyMaterialLogReadOf((string) file_get_contents($path));
        $sinks += $read['sinks'];

        foreach ($read['offenders'] as $offender) {
            $offenders[] = str_replace(RepoTree::root().'/', '', $path).': '.$offender;
        }
    }

    expect($sinks)->toBeGreaterThan(
        200,
        'the walk found almost no log call, and a walk that reads nothing reports the same clean tree does'
    );

    expect($offenders)->toBe([], 'a log line is written in the clear, kept forever, and copied into a bug report. '
        .'Name the epoch, the device and the reason — never the bytes, the passphrase, or the object holding them: '
        .implode(' | ', $offenders));
});

// The tree hands a logger no key material, so this rule reports on what it
// cannot find and the reader is driven against planted statements instead. The
// near-misses are the three shapes that are deliberately allowed: the epoch's
// id, a reason naming a field in prose, and the material never reaching a sink.
it('tells key material handed to a logger from the id, the reason and the value that never gets there', function (): void {
    $read = keyMaterialLogReadOf('<?php Log::error(\'unwrap failed\', [\'raw_key\' => $key]);');
    expect($read['sinks'])->toBe(1)
        ->and($read['offenders'])->toHaveCount(1);

    $epoch = keyMaterialLogReadOf('<?php $this->logger->debug(\'rotating\', [\'epoch\' => $epoch]);');
    expect($epoch['offenders'])->toHaveCount(1);

    $byId = keyMaterialLogReadOf('<?php Log::info(\'epoch refused\', [\'epoch_id\' => $epoch->epochId]);');
    expect($byId['sinks'])->toBe(1)
        ->and($byId['offenders'])->toBe([]);

    $reason = keyMaterialLogReadOf('<?php Log::warning(\'wrapped_key_b64 is not valid base64\');');
    expect($reason['sinks'])->toBe(1)
        ->and($reason['offenders'])->toBe([]);

    $unlogged = keyMaterialLogReadOf('<?php $rawKey = $custodian->unwrap($wrappedBin);');
    expect($unlogged['sinks'])->toBe(0)
        ->and($unlogged['offenders'])->toBe([]);

    $commented = keyMaterialLogReadOf("<?php // Log::error('leak', ['raw_key' => \$k]);\n");
    expect($commented['sinks'])->toBe(0);
});
