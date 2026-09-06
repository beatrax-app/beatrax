<?php

declare(strict_types=1);

// A picker offering a format nothing can parse fails silently: the chip
// highlights, the file uploads, and the import dies inside the pipeline as one
// unreadable-file error the user cannot act on.
/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md
 */

use Modules\Core\Public\Support\PatternScan;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ingestion\Public\Services\SourceAdapterRegistry;

// The picker components are read as source rather than imported, because naming
// another module's Internal component from here would weld this rule to a
// private shape its owner is entitled to change.
/**
 * @return list<string> relative paths to every Livewire component file
 */
function offeredFormatComponentFiles(): array
{
    $files = [];
    foreach (glob(base_path('Modules/*/{Internal,Public}/Http/Livewire'), GLOB_ONLYDIR | GLOB_BRACE) ?: [] as $directory) {
        $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($tree as $file) {
            if ($file instanceof SplFileInfo && str_ends_with($file->getFilename(), '.php')) {
                $files[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
    }
    sort($files);

    return $files;
}

// Stripped before matching, so a format id named in a comment — every one of
// these files explains its own list — does not read as an entry in it.
function offeredFormatSourceWithoutComments(string $path): string
{
    $contents = (string) file_get_contents($path);

    return preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
}

/**
 * @return array<string, string> short class name => fully-qualified name
 */
function offeredFormatImportMap(string $contents): array
{
    $imports = PatternScan::all('/^use\s+([A-Za-z0-9_\\\\]+);/m', $contents);

    $qualify = [];
    foreach ($imports[1] as $fqcn) {
        $parts = explode('\\', $fqcn);
        $qualify[end($parts)] = $fqcn;
    }

    return $qualify;
}

// A quoted literal is the format itself; `Foo::BAR` and `Foo::Case->value` both
// resolve through the file's own imports, so a format named by constant counts
// exactly as one written out.
/**
 * @param  array<string, string>  $qualify
 * @return list<string> the resolved values, and the tokens that resolved to none
 */
function offeredFormatValues(string $body, array $qualify): array
{
    $tokens = PatternScan::sets(
        '/\'([^\']+)\'|([A-Za-z_][A-Za-z0-9_]*)::([A-Za-z_][A-Za-z0-9_]*)/',
        $body,
    );

    $values = [];
    foreach ($tokens as $token) {
        if (($token[1] ?? '') !== '') {
            $values[] = $token[1];

            continue;
        }

        $fqcn = $qualify[$token[2]] ?? null;
        $name = $fqcn === null ? null : $fqcn.'::'.$token[3];
        if ($name === null || ! defined($name)) {
            $values[] = 'UNRESOLVED '.$token[2].'::'.$token[3];

            continue;
        }

        $resolved = constant($name);
        $values[] = $resolved instanceof BackedEnum ? (string) $resolved->value : (string) $resolved;
    }

    return $values;
}

/**
 * @return array<string, list<string>> relative path => every format it offers
 */
function offeredFormatsByComponent(): array
{
    $offered = [];

    foreach (offeredFormatComponentFiles() as $relative) {
        $contents = offeredFormatSourceWithoutComments(base_path($relative));
        $qualify = offeredFormatImportMap($contents);

        $lists = PatternScan::sets('/SUPPORTED_FORMATS\s*=\s*\[(.*?)\];/s', $contents);
        $defaults = PatternScan::sets('/public\s+string\s+\$(?:selectedFormat|sourceFormat)\s*=\s*([^;]+);/', $contents);

        $values = [];
        foreach ([...$lists, ...$defaults] as $match) {
            $values = [...$values, ...offeredFormatValues($match[1], $qualify)];
        }

        if ($values !== []) {
            $offered[$relative] = array_values(array_unique($values));
        }
    }

    return $offered;
}

// A receipt file carries no account, so ParseStage routes it to the receipt
// recorder rather than the adapter registry — which is why these count as
// parseable while binding no adapter. The enum owns the pair, so this asks it
// instead of reading a constant a call site might keep its own copy of.
/**
 * @return list<string>
 */
function offeredFormatReceiptArm(): array
{
    return SourceFormat::receiptFormats();
}

it('offers no source format that nothing in the app can parse', function (): void {
    /** @var SourceAdapterRegistry $registry */
    $registry = $this->app->make(SourceAdapterRegistry::class);
    $parseable = [...$registry->supportedFormats(), ...offeredFormatReceiptArm()];

    $offered = offeredFormatsByComponent();

    // Read before the verdict: a glob that stops matching answers with an empty
    // picker list, which is indistinguishable from every picker being correct.
    expect(count(offeredFormatComponentFiles()))->toBeGreaterThan(60, 'The Livewire component walk found almost nothing, so a clean answer below means nothing.')
        ->and(count($offered))->toBeGreaterThan(2, 'No upload picker was read as offering a format, so the rule below can only pass vacuously.');

    $orphans = [];
    foreach ($offered as $relative => $formats) {
        foreach ($formats as $format) {
            if (! in_array($format, $parseable, strict: true)) {
                $orphans[] = $relative.' offers '.$format;
            }
        }
    }
    sort($orphans);

    expect($orphans)->toBe(
        [],
        'An upload picker offers a source format that resolves to no parser, so choosing it is a dead '
        .'end: SourceAdapterRegistry::for() raises UnsupportedFormatException and the user sees only an '
        .'unreadable-file error. Bind an adapter for it, or take it off the picker. Offenders:'
        ."\n  ".implode("\n  ", $orphans)
        ."\nParseable today: ".implode(', ', $parseable),
    );
});

it('binds a parser for every format the SourceFormat enum names', function (): void {
    /** @var SourceAdapterRegistry $registry */
    $registry = $this->app->make(SourceAdapterRegistry::class);
    $parseable = [...$registry->supportedFormats(), ...offeredFormatReceiptArm()];

    $orphans = [];
    foreach (SourceFormat::cases() as $case) {
        if (! in_array($case->value, $parseable, strict: true)) {
            $orphans[] = $case->name.' = '.$case->value;
        }
    }

    expect($orphans)->toBe(
        [],
        'SourceFormat names a format the app cannot parse. The enum is what the wizards, the stored '
        .'source_format column and the filename seam all dispatch on, so a case with no adapter behind it '
        .'reads as a supported format everywhere and works nowhere. Either bind it in '
        .'IngestionServiceProvider or delete the case. Orphans:'
        ."\n  ".implode("\n  ", $orphans),
    );
});

it('reads a format written as a constant as the same offer as one written out', function (): void {
    $qualify = offeredFormatImportMap("<?php\n\nuse ".SourceFormat::class.";\n");

    expect(array_key_exists('SourceFormat', $qualify))->toBeTrue('The import map no longer resolves a short class name, so every constant below reads as unresolved.');

    // The three spellings a picker uses, and the one the scan must refuse to
    // guess at: an unimported name resolves to no constant and is reported
    // rather than dropped, so a renamed enum surfaces as an orphan.
    expect(offeredFormatValues("['camt053', SourceFormat::Mt940, SourceFormat::IcsPdf->value]", $qualify))
        ->toBe(['camt053', 'mt940', 'ics-pdf'])
        ->and(offeredFormatValues('[Unimported::Whatever]', $qualify))
        ->toBe(['UNRESOLVED Unimported::Whatever']);
});
