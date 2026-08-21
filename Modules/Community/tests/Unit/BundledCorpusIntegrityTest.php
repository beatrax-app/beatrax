<?php

declare(strict_types=1);

use Modules\Community\Internal\Corpus\MerchantContactReader;
use Modules\Community\Public\Services\CorpusPatternMatcher;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * @return list<array{file: string, index: int, raw: array<int|string, mixed>}>
 */
function bundledMerchantEntries(): array
{
    // Parsed once: at ~6,700 entries across 46 files, re-reading per case turned
    // a fast structural check into the slowest test in the module.
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $found = glob(base_path('resources/corpus/merchants/*.yaml'));
    $files = $found !== false ? $found : [];
    sort($files);

    $entries = [];
    foreach ($files as $file) {
        $parsed = Yaml::parseFile($file, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        expect($parsed)->toBeArray("{$file} must parse to a mapping");
        expect($parsed['entries'] ?? null)->toBeArray("{$file} must carry an `entries:` list");

        foreach ($parsed['entries'] as $index => $raw) {
            expect($raw)->toBeArray("{$file} entry #{$index} must be a mapping");
            $entries[] = ['file' => basename($file), 'index' => $index, 'raw' => $raw];
        }
    }

    expect($entries)->not->toBe([], 'the bundled merchant corpus must not be empty');

    return $cached = $entries;
}

/** @return object&LoggerInterface a PSR-3 sink recording only the messages it was given */
function recordingCorpusLogger(): object
{
    return new class extends AbstractLogger
    {
        /** @var list<string> */
        public array $warnings = [];

        public function log($level, $message, array $context = []): void
        {
            $this->warnings[] = (string) $message.' '.json_encode($context, JSON_THROW_ON_ERROR);
        }
    };
}

it('gives every bundled merchant entry a pattern and a name', function (): void {
    $offenders = [];
    foreach (bundledMerchantEntries() as $entry) {
        $pattern = $entry['raw']['pattern'] ?? null;
        $name = $entry['raw']['name'] ?? null;
        if (! is_string($pattern) || trim($pattern) === '' || ! is_string($name) || trim($name) === '') {
            $offenders[] = $entry['file'].' #'.$entry['index'];
        }
    }

    expect($offenders)->toBe([], "Every corpus entry needs a non-empty pattern and name. Offenders:\n  ".implode("\n  ", $offenders));
});

it('keeps every merchant pattern unique across the whole corpus', function (): void {
    // The global corpus tier is keyed on (pattern) alone, so a duplicate across
    // two country files is not cosmetic: the later file overwrites the earlier
    // row wholesale, replacing its contact data with the copy's nulls.
    $seen = [];
    $duplicates = [];
    foreach (bundledMerchantEntries() as $entry) {
        $pattern = is_string($entry['raw']['pattern'] ?? null) ? trim($entry['raw']['pattern']) : '';
        if ($pattern === '') {
            continue;
        }
        if (isset($seen[$pattern])) {
            $duplicates[] = "{$pattern} ({$seen[$pattern]} and {$entry['file']})";

            continue;
        }
        $seen[$pattern] = $entry['file'];
    }

    expect($duplicates)->toBe([], "Corpus patterns must be globally unique. Duplicates:\n  ".implode("\n  ", $duplicates));
});

it('compiles every regex pattern in the bundled corpus', function (): void {
    $broken = [];
    foreach (bundledMerchantEntries() as $entry) {
        $pattern = is_string($entry['raw']['pattern'] ?? null) ? trim($entry['raw']['pattern']) : '';
        if (! str_starts_with($pattern, CorpusPatternMatcher::REGEX_PREFIX)) {
            continue;
        }
        $body = substr($pattern, strlen(CorpusPatternMatcher::REGEX_PREFIX));
        if (@preg_match('#'.str_replace('#', '\#', $body).'#i', 'probe') === false) {
            $broken[] = $entry['file'].': '.$pattern;
        }
    }

    expect($broken)->toBe([], "Every `regex:` corpus pattern must compile. Broken:\n  ".implode("\n  ", $broken));
});

it('lands every contact value in the bundled corpus without being dropped', function (): void {
    // MerchantContactReader silently skips a malformed contact value at seed
    // time, so a typo in a cancel_url would ship as a missing link nobody
    // notices; failing on any warning turns that silent drop into a red build.
    $logger = recordingCorpusLogger();
    $reader = new MerchantContactReader($logger);

    foreach (bundledMerchantEntries() as $entry) {
        $pattern = is_string($entry['raw']['pattern'] ?? null) ? trim($entry['raw']['pattern']) : '';
        $reader->read($entry['raw'], $entry['file'].': '.$pattern);
    }

    expect($logger->warnings)->toBe([], "Corpus contact values must all be well-formed. Rejected:\n  ".implode("\n  ", $logger->warnings));
});

it('gives every classification rule a compiling pattern', function (string $type): void {
    // Government and bank-fee rules are matched against every user's descriptions
    // regardless of country, so one that fails to compile silently loses a whole
    // country's classification.
    $found = glob(base_path("resources/corpus/{$type}/*.yaml"));
    $files = $found !== false ? $found : [];
    sort($files);
    expect($files)->not->toBe([], "the {$type} corpus must not be empty");

    $offenders = [];
    foreach ($files as $file) {
        $parsed = Yaml::parseFile($file, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        expect($parsed['entries'] ?? null)->toBeArray("{$file} must carry an `entries:` list");

        foreach ($parsed['entries'] as $index => $raw) {
            $pattern = is_array($raw) && is_string($raw['pattern'] ?? null) ? trim($raw['pattern']) : '';
            if ($pattern === '') {
                $offenders[] = basename($file)." #{$index}: missing pattern";

                continue;
            }
            if (! str_starts_with($pattern, CorpusPatternMatcher::REGEX_PREFIX)) {
                continue;
            }
            $body = substr($pattern, strlen(CorpusPatternMatcher::REGEX_PREFIX));
            if (@preg_match('#'.str_replace('#', '\#', $body).'#i', 'probe') === false) {
                $offenders[] = basename($file).': '.$pattern;
            }
        }
    }

    expect($offenders)->toBe([], "Every {$type} rule must carry a compiling pattern. Offenders:\n  ".implode("\n  ", $offenders));
})->with(['government', 'bank-fees']);

it('keeps every regex classification rule ASCII-only', function (string $type): void {
    // CorpusPatternMatcher delimits a regex body as `#...#i` with no /u flag, so
    // case folding and \b are ASCII-only there; a Greek or Cyrillic term belongs
    // in the literal path, where mb_stripos folds multibyte correctly.
    $found = glob(base_path("resources/corpus/{$type}/*.yaml"));

    $offenders = [];
    foreach ($found !== false ? $found : [] as $file) {
        $parsed = Yaml::parseFile($file, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        foreach ($parsed['entries'] as $raw) {
            $pattern = is_array($raw) && is_string($raw['pattern'] ?? null) ? trim($raw['pattern']) : '';
            if (str_starts_with($pattern, CorpusPatternMatcher::REGEX_PREFIX)
                && preg_match('/^[\x20-\x7E]*$/', $pattern) !== 1) {
                $offenders[] = basename($file).': '.$pattern;
            }
        }
    }

    expect($offenders)->toBe([], "A `regex:` rule must stay ASCII. Offenders:\n  ".implode("\n  ", $offenders));
})->with(['government', 'bank-fees']);

it('uses only recognised contact keys on bundled merchant entries', function (): void {
    // An unrecognised key is not rejected by the reader — it is ignored — so a
    // misspelled `cancel_ur:` would ship as a silently absent link.
    $known = ['pattern', 'generalized_pattern', 'name', 'category', 'region', 'contributor',
        'website', 'cancel_url', 'support_url', 'support_phone', 'support_email'];

    $unknown = [];
    foreach (bundledMerchantEntries() as $entry) {
        foreach (array_keys($entry['raw']) as $key) {
            if (! in_array($key, $known, true)) {
                $unknown[] = $entry['file'].' #'.$entry['index'].': '.(string) $key;
            }
        }
    }

    expect($unknown)->toBe([], "Corpus entries must use only documented keys. Unknown keys:\n  ".implode("\n  ", $unknown));
});
