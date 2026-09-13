<?php

declare(strict_types=1);

use Modules\Mobile\Internal\Boot\ShippedPermissions;

// The strip script edits the app manifest and runs BEFORE Gradle merges a
// dependency's own into it — its header says so. So what the artifact actually
// requests is a different question from what the source declares, and only the
// merged manifest inside the built APK answers it.

function shippedPermissions(): ShippedPermissions
{
    return app(ShippedPermissions::class);
}

// Shaped like real `aapt2 dump xmltree` output, attribute order included: the
// name carries resource id 0x01010003 and the level 0x01010009, so the name is
// always printed first and the parser is allowed to rely on that.
/**
 * @param  list<string>  $requested
 * @param  array<string, int>  $declared
 */
function xmlTree(array $requested, array $declared = [], string $package = 'com.beatrax.mobile'): string
{
    $ns = 'http://schemas.android.com/apk/res/android';
    $lines = ['N: android='.$ns.' (line=2)', '  E: manifest (line=2)', '    A: package="'.$package.'" (Raw: "'.$package.'")'];

    foreach ($declared as $name => $level) {
        $lines[] = '      E: permission (line=27)';
        $lines[] = '        A: '.$ns.':name(0x01010003)="'.$name.'" (Raw: "'.$name.'")';

        if ($level >= 0) {
            $lines[] = sprintf('        A: %s:protectionLevel(0x01010009)=0x%08x', $ns, $level);
        }
    }

    foreach ($requested as $name) {
        $lines[] = '      E: uses-permission (line=11)';
        $lines[] = '        A: '.$ns.':name(0x01010003)="'.$name.'" (Raw: "'.$name.'")';
    }

    return implode("\n", $lines)."\n";
}

/** @param list<string> $requested */
function permissionRefusals(array $requested, array $declared = [], string $package = 'com.beatrax.mobile'): array
{
    return shippedPermissions()->refusals(shippedPermissions()->readManifest(xmlTree($requested, $declared, $package)));
}

it('reads the package, the requests and the declared levels out of an xmltree dump', function (): void {
    $manifest = shippedPermissions()->readManifest(xmlTree(
        ['android.permission.INTERNET', 'android.permission.CAMERA'],
        ['com.beatrax.mobile.OWN' => 0x2],
    ));

    expect($manifest['package'])->toBe('com.beatrax.mobile')
        ->and($manifest['requested'])->toBe(['android.permission.INTERNET', 'android.permission.CAMERA'])
        ->and($manifest['declared'])->toBe(['com.beatrax.mobile.OWN' => 0x2]);
});

it('accepts exactly the set this product makes a use for', function (): void {
    expect(permissionRefusals(array_keys(ShippedPermissions::ALLOWED)))->toBe([]);
});

it('names every allowed permission a consumer that is really there', function (): void {
    // The list is the claim "we make this use". A consumer that has been
    // deleted or renamed turns the claim into a permission nothing needs, and
    // a store asks about exactly those.
    $missing = [];

    foreach (ShippedPermissions::ALLOWED as $permission => $consumer) {
        if (! class_exists($consumer)) {
            $missing[] = $permission.' -> '.$consumer;
        }
    }

    expect($missing)->toBe([], implode("\n", $missing));
});

it('refuses a restricted permission that came back through the merge', function (string $permission): void {
    $refusals = permissionRefusals([...array_keys(ShippedPermissions::ALLOWED), $permission]);

    expect($refusals)->toHaveCount(1);
    expect($refusals[0])->toContain('a store restricts it')
        ->and($refusals[0])->toContain($permission);
})->with(ShippedPermissions::REFUSED);

it('refuses a permission nothing in this product names at all', function (): void {
    $refusals = permissionRefusals([
        ...array_keys(ShippedPermissions::ALLOWED),
        'android.permission.ACCESS_FINE_LOCATION',
    ]);

    expect($refusals)->toHaveCount(1);
    expect($refusals[0])->toContain('named nowhere in this product');
});

// androidx.core mints this one so a receiver registered at runtime is not
// world-reachable on API 33 and up. It is a lock rather than a key: no app
// signed by another key can hold it, and no PHP class can be named as its
// consumer because the consumer is the Android shell.
it('accepts a signature permission the artifact declares for itself', function (): void {
    $own = 'com.beatrax.mobile.DYNAMIC_RECEIVER_NOT_EXPORTED_PERMISSION';

    expect(permissionRefusals([...array_keys(ShippedPermissions::ALLOWED), $own], [$own => 0x2]))->toBe([]);
});

it('refuses the same self-declared permission at a level another app can hold', function (int $level): void {
    $own = 'com.beatrax.mobile.DYNAMIC_RECEIVER_NOT_EXPORTED_PERMISSION';
    $refusals = permissionRefusals([...array_keys(ShippedPermissions::ALLOWED), $own], [$own => $level]);

    // Twice over: the request is no longer self-protection, and the
    // declaration itself is now something another app can ask for and get.
    expect($refusals)->toHaveCount(2)
        ->and(implode("\n", $refusals))->toContain('declared by the artifact at a level other apps can hold')
        ->and(implode("\n", $refusals))->toContain('named nowhere in this product');
})->with([
    'normal' => 0x0,
    'dangerous' => 0x1,
    'signature plus privileged' => 0x12,
]);

it('refuses a self-declared permission that omits the level, which the platform reads as normal', function (): void {
    $own = 'com.beatrax.mobile.DYNAMIC_RECEIVER_NOT_EXPORTED_PERMISSION';

    // -1 makes the fixture leave the attribute out entirely. A parser that
    // records only the levels it was shown reads that silence as "never
    // declared" and waves the widest protection level there is straight past.
    $refusals = permissionRefusals([...array_keys(ShippedPermissions::ALLOWED), $own], [$own => -1]);

    expect(implode("\n", $refusals))->toContain('declared by the artifact at a level other apps can hold');
});

it('refuses a badly declared permission that nothing requests at all', function (int $level): void {
    // The half a request-side check cannot reach. A declaration guards this
    // artefact's own components whether or not the manifest goes on to ask
    // for it, so a level another app can hold is an exposure on its own.
    $own = 'com.beatrax.mobile.DYNAMIC_RECEIVER_NOT_EXPORTED_PERMISSION';
    $refusals = permissionRefusals(array_keys(ShippedPermissions::ALLOWED), [$own => $level]);

    expect($refusals)->toHaveCount(1)
        ->and($refusals[0])->toContain('declared by the artifact at a level other apps can hold')
        ->and($refusals[0])->toContain($own);
})->with([
    'normal' => 0x0,
    'dangerous' => 0x1,
    'signature plus privileged' => 0x12,
    'the attribute left out, which the platform reads as normal' => -1,
]);

it('accepts a qualifying declaration that nothing requests', function (): void {
    $own = 'com.beatrax.mobile.DYNAMIC_RECEIVER_NOT_EXPORTED_PERMISSION';

    expect(permissionRefusals(array_keys(ShippedPermissions::ALLOWED), [$own => 0x2]))->toBe([]);
});

it('refuses a signature permission scoped to somebody else package', function (): void {
    $theirs = 'com.example.other.SOME_PERMISSION';
    $refusals = permissionRefusals([...array_keys(ShippedPermissions::ALLOWED), $theirs], [$theirs => 0x2]);

    expect($refusals)->toHaveCount(1)
        ->and($refusals[0])->toContain('named nowhere in this product');
});

it('refuses a self-namespaced permission the artifact requests but never declares', function (): void {
    $refusals = permissionRefusals([...array_keys(ShippedPermissions::ALLOWED), 'com.beatrax.mobile.UNDECLARED']);

    expect($refusals)->toHaveCount(1)
        ->and($refusals[0])->toContain('named nowhere in this product');
});

it('fails the command on a dump that names no permission', function (): void {
    // The shape a changed aapt2 output takes. Read as "requests nothing" it
    // would satisfy every rule by naming none of them.
    $path = sys_get_temp_dir().'/perm-dump-'.bin2hex(random_bytes(6)).'.txt';
    file_put_contents($path, xmlTree([]));

    $this->artisan('mobile:check-permissions', ['dump' => $path])->assertExitCode(1);

    @unlink($path);
});

it('fails the command on a dump with no package to scope a permission against', function (): void {
    $path = sys_get_temp_dir().'/perm-dump-'.bin2hex(random_bytes(6)).'.txt';
    file_put_contents($path, xmlTree(array_keys(ShippedPermissions::ALLOWED), [], ''));

    $this->artisan('mobile:check-permissions', ['dump' => $path])->assertExitCode(1);

    @unlink($path);
});

it('passes the command on the set that ships', function (): void {
    $path = sys_get_temp_dir().'/perm-dump-'.bin2hex(random_bytes(6)).'.txt';
    file_put_contents($path, xmlTree(array_keys(ShippedPermissions::ALLOWED)));

    $this->artisan('mobile:check-permissions', ['dump' => $path])->assertExitCode(0);

    @unlink($path);
});

it('fails the command when the dump is not there', function (): void {
    $this->artisan('mobile:check-permissions', ['dump' => sys_get_temp_dir().'/no-such-dump.txt'])
        ->assertExitCode(1);
});
