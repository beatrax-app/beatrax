<?php

declare(strict_types=1);

use Modules\Mobile\Internal\Boot\NativeBuildPatches;
use Symfony\Component\Process\Process;

// NativePHP.entitlements is generated output: `native:build ios` rewrites it
// from scratch out of three config keys, and an entitlement written into it
// beforehand is gone by the time anything signs. The only declaration that
// survives is one IOSPluginCompiler merges back afterwards, so what is proved
// here is that the compiler was taught to read the app's own config.
function entitlementsCompilerStub(): string
{
    return <<<'PHP'
        <?php

        namespace Native\Mobile\Plugins\Compilers;

        class IOSPluginCompiler
        {
            public function compile(): void
            {
                $hasAppLocalizations = ! empty($this->getAppInfoPlistLocalizations());

                // If no plugins have any iOS-related data, generate empty registrations
                if (
                    $pluginsWithCode->isEmpty()
                    && $pluginsWithFunctions->isEmpty()
                    && $pluginsWithIosData->isEmpty()
                    && $pluginsWithRenderers->isEmpty()
                    && ! $hasAppLocalizations
                ) {
                    return;
                }

                $this->mergeEntitlements($allPlugins);
            }

            protected function mergeEntitlements($plugins): void
            {
                $entitlementsPath = $this->iosProjectPath.'/NativePHP/NativePHP.entitlements';

                // Collect all entitlements from plugins
                $allEntitlements = [];

                if (empty($allEntitlements)) {
                    return;
                }
            }
        }
        PHP;
}

/** @return string a fake native root carrying the mobile vendor file the patch rewrites */
function entitlementsFakeRoot(): string
{
    $root = sys_get_temp_dir().'/beatrax-entitlement-'.bin2hex(random_bytes(6));
    $compiler = $root.'/vendor/nativephp/mobile/src/Plugins/Compilers';

    mkdir($compiler, 0o755, true);
    file_put_contents($compiler.'/IOSPluginCompiler.php', entitlementsCompilerStub()."\n");

    return $root;
}

function runEntitlementPatch(string $root): Process
{
    $scripts = NativeBuildPatches::locate(base_path());

    expect($scripts)->not->toBeNull();

    $process = new Process(
        [PHP_BINARY, $scripts.'/nativephp_ios_local_network_discovery.php'],
        env: ['BEATRAX_NATIVE_ROOT' => $root],
    );
    $process->run();

    return $process;
}

it('teaches the iOS plugin compiler to merge the app-declared entitlements', function (): void {
    $root = entitlementsFakeRoot();
    $compiler = $root.'/vendor/nativephp/mobile/src/Plugins/Compilers/IOSPluginCompiler.php';

    $process = runEntitlementPatch($root);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $patched = (string) file_get_contents($compiler);

    expect($patched)->toContain("\$allEntitlements = (array) config('nativephp.entitlements', []);");
    expect($patched)->not->toContain('$allEntitlements = [];');

    // The merge is unreachable without this: a tree whose plugins ship no iOS
    // data returns from compile() several statements earlier.
    expect($patched)->toContain("&& empty(config('nativephp.entitlements'))");
});

it('re-runs over an already patched compiler without changing it again', function (): void {
    $root = entitlementsFakeRoot();
    $compiler = $root.'/vendor/nativephp/mobile/src/Plugins/Compilers/IOSPluginCompiler.php';

    runEntitlementPatch($root);
    $once = (string) file_get_contents($compiler);

    runEntitlementPatch($root);

    expect((string) file_get_contents($compiler))->toBe($once);
});

it('fails loudly when the upstream anchor it rewrites has moved', function (): void {
    $root = entitlementsFakeRoot();
    $compiler = $root.'/vendor/nativephp/mobile/src/Plugins/Compilers/IOSPluginCompiler.php';

    file_put_contents($compiler, str_replace(
        '$allEntitlements = [];',
        '$allEntitlements = collect();',
        (string) file_get_contents($compiler),
    ));

    $process = runEntitlementPatch($root);

    expect($process->isSuccessful())->toBeFalse();
    expect($process->getErrorOutput())->toContain('anchor not found');
});

it('declares the multicast entitlement in committed config rather than generated output', function (): void {
    $config = (string) file_get_contents(base_path(
        is_file(base_path('mobile-app/config/nativephp.php')) ? 'mobile-app/config/nativephp.php' : 'config/nativephp.php',
    ));

    expect($config)->toContain("'entitlements' =>");
    expect($config)->toContain('com.apple.developer.networking.multicast');
    expect($config)->toContain('IOS_MULTICAST_ENTITLEMENT');
});
