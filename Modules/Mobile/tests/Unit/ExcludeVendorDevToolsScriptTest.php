<?php

declare(strict_types=1);

use Modules\Mobile\Internal\Boot\NativeBuildPatches;
use Symfony\Component\Process\Process;

// amphp/http-server/tools/tls/ travelled into the Laravel bundle and a signed
// release APK shipped two live PRIVATE KEY blocks. BundleExclusions drops a
// package's docs and its tests; tools was the sibling nobody listed.

/** @return string a fake native root holding a vendored BundleExclusions */
function excludeVendorDevToolsScaffold(string $patternsList): string
{
    $root = sys_get_temp_dir().'/beatrax-devtools-'.bin2hex(random_bytes(6));

    mkdir($root.'/vendor/nativephp/mobile/src/Support', 0o755, true);

    $source = "<?php\n\nnamespace Native\\Mobile\\Support;\n\nclass BundleExclusions\n{\n"
        .$patternsList
        ."}\n";

    file_put_contents($root.'/vendor/nativephp/mobile/src/Support/BundleExclusions.php', $source);

    return $root;
}

function excludeVendorDevToolsPatternsList(): string
{
    return "    public const VENDOR_PATTERNS = [\n        '*.md',\n        'docs',\n    ];\n";
}

function runExcludeVendorDevToolsPatch(string $root): Process
{
    $scripts = NativeBuildPatches::locate(base_path());

    expect($scripts)->not->toBeNull();

    $process = new Process(
        [PHP_BINARY, $scripts.'/nativephp_exclude_vendor_dev_tools.php'],
        env: ['BEATRAX_NATIVE_ROOT' => $root],
    );
    $process->run();

    return $process;
}

function excludeVendorDevToolsSource(string $root): string
{
    return (string) file_get_contents($root.'/vendor/nativephp/mobile/src/Support/BundleExclusions.php');
}

it('adds tools to the patterns applied under vendor', function (): void {
    $root = excludeVendorDevToolsScaffold(excludeVendorDevToolsPatternsList());

    $process = runExcludeVendorDevToolsPatch($root);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    expect(excludeVendorDevToolsSource($root))->toContain("VENDOR_PATTERNS = [\n        'tools',");
});

it('re-runs without changing a byte', function (): void {
    $root = excludeVendorDevToolsScaffold(excludeVendorDevToolsPatternsList());

    runExcludeVendorDevToolsPatch($root);

    $before = excludeVendorDevToolsSource($root);

    $repeat = runExcludeVendorDevToolsPatch($root);

    expect($repeat->isSuccessful())->toBeTrue($repeat->getErrorOutput());
    expect(excludeVendorDevToolsSource($root))->toBe($before);
});

// The list is a vendor constant, so an upstream rename is what this has to
// report. Silence here ships the key material the patch exists to remove.
it('refuses a vendor tree whose pattern list it no longer recognises', function (): void {
    $root = excludeVendorDevToolsScaffold("    public const SOMETHING_ELSE = [\n        'docs',\n    ];\n");

    $process = runExcludeVendorDevToolsPatch($root);

    expect($process->isSuccessful())->toBeFalse();
    expect($process->getErrorOutput())->toContain('Anchor not found');
});

// Every platform's build runs the whole patch list, and only the mobile root
// installs nativephp/mobile. This script is REQUIRED, so failing on a tree that
// never had the package aborts the build — which is exactly what it did to
// macOS, Windows and Linux before this case existed.
it('skips cleanly where there is no mobile vendor tree at all', function (): void {
    $root = sys_get_temp_dir().'/beatrax-devtools-none-'.bin2hex(random_bytes(6));

    mkdir($root, 0o755, true);

    $process = runExcludeVendorDevToolsPatch($root);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    expect($process->getOutput())->toContain('no mobile vendor tree here');
});
