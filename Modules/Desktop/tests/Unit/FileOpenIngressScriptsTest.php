<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Desktop\Internal\Native\FileOpenIntake;

// Two prebuild hooks and one deletion, all serving the same fact: the intake
// could never run. No fileAssociations meant no OS routed a .csv or .eml here,
// so macOS never fired open-file; Windows and Linux deliver the path on argv,
// which nothing read; and the HTTP route that was supposed to carry it sits in
// the `web` group, where a main-process POST can only answer 419.

beforeEach(function (): void {
    // Both scripts guard their top-level block behind an $isDirectlyInvoked
    // check that is false under Pest, so requiring them only defines helpers.
    require_once base_path('scripts/nativephp_inject_file_associations.php');
    require_once base_path('scripts/nativephp_inject_file_open_ingress.php');
});

function fileOpenBuilderStub(): string
{
    return <<<'JS'
        export default {
            appId: appId,
            productName: appName,
            protocols: {
                name: deepLinkProtocol,
                schemes: [deepLinkProtocol],
            },
            mac: {
                entitlementsInherit: 'build/entitlements.mac.plist',
            },
        };
        JS;
}

function fileOpenMainProcessStub(): string
{
    return <<<'JS'
        import NativePHP from '#plugin';
        import { app } from 'electron';

        NativePHP.bootstrap(app, defaultIcon, phpBinary, certificate, appPath);
        JS;
}

it('declares a document type for every extension the intake accepts, and none it would refuse', function (): void {
    // An extension the OS routes here that the intake refuses is a
    // double-click that opens the app and does nothing; one the intake accepts
    // but no OS routes is the state this whole change came out of.
    $declared = array_keys(fileAssociationTypes());
    sort($declared);

    $accepted = FileOpenIntake::SUPPORTED_EXTENSIONS;
    sort($accepted);

    expect($declared)->toBe($accepted);
});

it('splices fileAssociations into the builder config beside the protocols key', function (): void {
    [$patched, $status] = injectFileAssociations(fileOpenBuilderStub());

    expect($status)->toBe('patched');
    expect($patched)->toContain('fileAssociations: [');
    expect($patched)->toContain("ext: 'csv'");
    expect($patched)->toContain("mimeType: 'message/rfc822'");
    expect(strpos($patched, 'fileAssociations: ['))->toBeLessThan(strpos($patched, 'protocols: {'));
});

it('leaves a builder config that already declares fileAssociations untouched', function (): void {
    $already = "export default {\n    fileAssociations: [],\n    protocols: {},\n};";

    [$patched, $status] = injectFileAssociations($already);

    expect($status)->toBe('already-patched');
    expect($patched)->toBe($already);
});

it('reports failure when the builder config carries no protocols key to anchor against', function (): void {
    [$patched, $status] = injectFileAssociations("export default {\n    appId: appId,\n};");

    expect($patched)->toBeNull();
    expect($status)->toContain('protocols');
});

it('wires the single-instance lock, the argv scan and second-instance into the main process', function (): void {
    [$patched, $status] = injectFileOpenIngress(fileOpenMainProcessStub());

    expect($status)->toBe('patched');
    expect($patched)->toContain('app.requestSingleInstanceLock()');
    expect($patched)->toContain("app.on('second-instance'");
    expect($patched)->toContain('beatraxDocumentFromArgv(process.argv)');
});

it('forwards the path through NativePHP\'s own authenticated event transport, never a route of its own', function (): void {
    // notifyLaravel already presents X-NativePHP-Secret to the loopback PHP
    // server. A Beatrax route in the `web` group could only answer 419 to a
    // main-process POST, which carries neither session nor token.
    [$patched] = injectFileOpenIngress(fileOpenMainProcessStub());

    expect($patched)->toContain("notifyLaravel('events'");
    expect($patched)->toContain('\\\\Native\\\\Desktop\\\\Events\\\\App\\\\OpenFile');
    expect($patched)->not->toContain('/desktop/file-open');
});

it('waits for the PHP port before posting, so a cold-start document is not swallowed', function (): void {
    // notifyLaravel catches and discards its own transport failure, so posting
    // before the server binds loses the path with no trace at all.
    [$patched] = injectFileOpenIngress(fileOpenMainProcessStub());

    expect($patched)->toContain('NativePHPState.phpPort');
    expect($patched)->toContain('BEATRAX_FILE_OPEN_BOOT_ATTEMPTS');
});

it('scans argv from the end and skips flags', function (): void {
    // Electron puts its own switches ahead of the document the shell appended.
    [$patched] = injectFileOpenIngress(fileOpenMainProcessStub());

    expect($patched)->toContain('for (let i = argv.length - 1; i >= 1; i--)');
    expect($patched)->toContain("candidate.startsWith('-')");
});

it('is idempotent — a main process already carrying the marker is returned unchanged', function (): void {
    $already = fileOpenMainProcessStub()."\n// -- Beatrax OS file-open ingress --\n";

    [$patched, $status] = injectFileOpenIngress($already);

    expect($status)->toBe('already-patched');
    expect($patched)->toBe($already);
});

it('reports failure when the NativePHP bootstrap call site is missing', function (): void {
    // A release that reshapes the bootstrap call must fail the build loudly
    // rather than ship a main process that silently drops every document.
    [$patched, $status] = injectFileOpenIngress("import { app } from 'electron';\n");

    expect($patched)->toBeNull();
    expect($status)->toContain('NativePHP.bootstrap');
});

it('keeps the argv scan and the intake looking for the same extensions', function (): void {
    $scanned = fileOpenIngressExtensions();
    sort($scanned);

    $accepted = FileOpenIntake::SUPPORTED_EXTENSIONS;
    sort($accepted);

    expect($scanned)->toBe($accepted);
});

it('registers no HTTP route for a file open — the main process has no session to present', function (): void {
    // The route answered 419 to every real POST and 204 only under the suite,
    // where ValidateCsrfToken short-circuits on runningUnitTests(). That gap
    // is why twenty tests passed over a dead ingress.
    expect(Route::has('desktop.file-open'))->toBeFalse();
});
