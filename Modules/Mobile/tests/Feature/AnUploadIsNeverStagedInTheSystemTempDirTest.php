<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Mobile\Internal\Http\Middleware\EncodedUploadTransport;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

// tempnam() does not fail when the directory it is handed cannot be written
// to: it writes into sys_get_temp_dir() and returns THAT path, with only a
// notice. So the 0700 app directory this transport promises was a promise
// only while the mkdir happened to work, and the file it stages is somebody's
// bank statement landing in a 1777 directory.

function stagingTempDirUser(): User
{
    return User::query()->create([
        'username' => 'staging-temp-dir-user',
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function stagingTempDirRequest(string $contents): Request
{
    $request = Request::create(
        '/livewire/upload-file',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        (string) json_encode([
            EncodedUploadTransport::FIELD => EncodedUploadTransport::MARKER,
            'files' => [[
                'key' => 'files[]',
                'name' => 'statement.csv',
                'type' => 'text/csv',
                'size' => strlen($contents),
                'sha256' => hash('sha256', $contents),
                'content' => base64_encode($contents),
            ]],
        ], JSON_THROW_ON_ERROR),
    );

    $request->input(EncodedUploadTransport::FIELD);

    return $request;
}

/**
 * The pathname the transport handed downstream, or null when it refused
 * before anything was staged.
 *
 * @return array{staged: string|null, status: int|null, failure: string|null}
 */
function stagingTempDirRun(Request $request): array
{
    $staged = null;
    $status = null;
    $failure = null;

    try {
        app(EncodedUploadTransport::class)->handle($request, function (Request $handled) use (&$staged): SymfonyResponse {
            $files = $handled->files->get('files');
            if (is_array($files) && $files[0] instanceof UploadedFile) {
                $staged = $files[0]->getPathname();
            }

            return new SymfonyResponse('', 204);
        });
    } catch (HttpException $e) {
        $status = $e->getStatusCode();
    } catch (Throwable $e) {
        $failure = $e::class.': '.$e->getMessage();
    }

    return ['staged' => $staged, 'status' => $status, 'failure' => $failure];
}

/** @return list<string> */
function stagingTempDirStrays(): array
{
    return glob(sys_get_temp_dir().'/beatrax-upload-*') ?: [];
}

it('refuses rather than staging the decoded file wherever tempnam felt like putting it', function (): void {
    test()->actingAs(stagingTempDirUser());

    // The parent, not the staging directory itself: the transport chmods its
    // own directory back to 0700 on every call, so an unwritable parent is
    // what actually stops the mkdir — and tempnam() carries on regardless.
    $root = rtrim(UserDataPathService::appPath(), '/');
    $dir = rtrim(UserDataPathService::appPath('tmp-uploads'), '/');

    foreach (glob($dir.'/*') ?: [] as $stale) {
        @unlink($stale);
    }
    @rmdir($dir);
    chmod($root, 0500);

    $strayBefore = stagingTempDirStrays();

    try {
        $result = stagingTempDirRun(stagingTempDirRequest('date,amount'.PHP_EOL.'2026-01-01,-42.00'));
    } finally {
        chmod($root, 0755);
    }

    $strayAfter = array_values(array_diff(stagingTempDirStrays(), $strayBefore));
    foreach ($strayAfter as $stray) {
        @unlink($stray);
    }

    expect($result['staged'])->toBeNull(
        'the decoded statement was staged outside the 0700 app directory this transport promises',
    );

    // tempnam() creates the fallback file before it emits its notice, and the
    // notice aborts the request before handle()'s finally has a path to clean
    // up — so every attempt leaves a file behind in a 1777 directory.
    expect($strayAfter)->toBe([]);

    expect($result['failure'])->toBeNull();
    expect($result['status'])->toBe(500);
});

it('stages inside the app directory when it can be written to', function (): void {
    test()->actingAs(stagingTempDirUser());

    $dir = rtrim(UserDataPathService::appPath('tmp-uploads'), '/');
    @mkdir($dir, 0700, true);
    chmod($dir, 0700);

    $result = stagingTempDirRun(stagingTempDirRequest('date,amount'.PHP_EOL.'2026-01-01,-42.00'));

    expect($result['status'])->toBeNull();
    expect($result['staged'])->not->toBeNull();
    expect(dirname((string) $result['staged']))->toBe(realpath($dir));
});
