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

/** @param  list<string>  $names */
function aapt2Dump(array $names): string
{
    $lines = ['package: com.beatrax.mobile'];

    foreach ($names as $name) {
        $lines[] = "uses-permission: name='".$name."'";
    }

    return implode("\n", $lines)."\n";
}

it('reads the permissions out of an aapt2 dump', function (): void {
    $dump = aapt2Dump(['android.permission.INTERNET', 'android.permission.CAMERA']);

    expect(shippedPermissions()->requestedIn($dump))
        ->toBe(['android.permission.INTERNET', 'android.permission.CAMERA']);
});

it('accepts exactly the set this product makes a use for', function (): void {
    $allowed = array_keys(ShippedPermissions::ALLOWED);

    expect(shippedPermissions()->refusals($allowed))->toBe([]);
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
    $refusals = shippedPermissions()->refusals([...array_keys(ShippedPermissions::ALLOWED), $permission]);

    expect($refusals)->toHaveCount(1);
    expect($refusals[0])->toContain('a store restricts it')
        ->and($refusals[0])->toContain($permission);
})->with(ShippedPermissions::REFUSED);

it('refuses a permission nothing in this product names at all', function (): void {
    $refusals = shippedPermissions()->refusals([
        ...array_keys(ShippedPermissions::ALLOWED),
        'android.permission.ACCESS_FINE_LOCATION',
    ]);

    expect($refusals)->toHaveCount(1);
    expect($refusals[0])->toContain('named nowhere in this product');
});

it('fails the command on a dump that names no permission', function (): void {
    // The shape a changed aapt2 output takes. Read as "requests nothing" it
    // would satisfy every rule by naming none of them.
    $path = sys_get_temp_dir().'/perm-dump-'.bin2hex(random_bytes(6)).'.txt';
    file_put_contents($path, "package: com.beatrax.mobile\n");

    $this->artisan('mobile:check-permissions', ['dump' => $path])->assertExitCode(1);

    @unlink($path);
});

it('passes the command on the set that ships', function (): void {
    $path = sys_get_temp_dir().'/perm-dump-'.bin2hex(random_bytes(6)).'.txt';
    file_put_contents($path, aapt2Dump(array_keys(ShippedPermissions::ALLOWED)));

    $this->artisan('mobile:check-permissions', ['dump' => $path])->assertExitCode(0);

    @unlink($path);
});

it('fails the command when the dump is not there', function (): void {
    $this->artisan('mobile:check-permissions', ['dump' => sys_get_temp_dir().'/no-such-dump.txt'])
        ->assertExitCode(1);
});
