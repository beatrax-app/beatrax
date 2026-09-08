<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Internal\Backup\StagedExportHandover;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;

uses(RefreshDatabase::class);

// Livewire delivers a download by running ob_start(), calling sendContent() and
// base64-encoding the buffer, so the archive would sit in PHP memory at 2.33x
// its size before anything sent it. An iPhone 12 mini reports memory_limit 128M
// with a page render already peaking at 99 MB.

// This route is the way out instead: a plain GET the shell downloads the way it
// downloads anything, streamed rather than buffered.
function stagedExportUser(): User
{
    return User::query()->create([
        'username' => 'staged-export-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function stagedExportArchive(string $contents = 'PK-not-really-a-zip'): string
{
    $staging = UserDataPathService::appPath('tmp-backups');
    @mkdir($staging, 0700, true);
    $path = $staging.DIRECTORY_SEPARATOR.'beatrax-export-test-'.bin2hex(random_bytes(4)).'.zip';
    file_put_contents($path, $contents);

    return $path;
}

it('streams the staged archive and takes it off disk', function (): void {
    $this->actingAs(stagedExportUser());

    /** @var StagedExportHandover $handover */
    $handover = app(StagedExportHandover::class);
    $path = stagedExportArchive('the-archive-body');
    $token = $handover->stage($path, 'beatrax-export-2026-09-08.zip');

    $response = $this->get('/help/data-locations/export/'.$token);

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('beatrax-export-2026-09-08.zip');
    expect($response->streamedContent())->toBe('the-archive-body');
    expect(is_file($path))->toBeFalse('the archive stayed in the container after it was collected');
});

it('answers a second use of the same token with a 404', function (): void {
    $this->actingAs(stagedExportUser());

    /** @var StagedExportHandover $handover */
    $handover = app(StagedExportHandover::class);
    $token = $handover->stage(stagedExportArchive(), 'beatrax-export-2026-09-08.zip');

    $this->get('/help/data-locations/export/'.$token)->assertOk();
    $this->get('/help/data-locations/export/'.$token)->assertNotFound();
});

it('answers a token nobody staged with a 404', function (): void {
    $this->actingAs(stagedExportUser());

    $this->get('/help/data-locations/export/'.str_repeat('a', 32))->assertNotFound();
});

// The claim is re-checked against the staging directory at download time rather
// than trusted from the session, so naming a path is never a way to read one.
it('refuses a claim pointing outside the staging directory', function (): void {
    $this->actingAs(stagedExportUser());

    $outside = UserDataPathService::appPath('beatrax-not-an-export.txt');
    file_put_contents($outside, 'the keyring, for example');

    /** @var Session $session */
    $session = app(Session::class);
    $session->put('beatrax.staged_exports', [
        str_repeat('b', 32) => ['path' => $outside, 'name' => 'anything.zip'],
    ]);

    $this->get('/help/data-locations/export/'.str_repeat('b', 32))->assertNotFound();

    expect(is_file($outside))->toBeTrue('a refused claim deleted the file it named');
    @unlink($outside);
});

it('takes no token shape but the one it issues', function (): void {
    $this->actingAs(stagedExportUser());

    $this->get('/help/data-locations/export/../../etc/passwd')->assertNotFound();
    $this->get('/help/data-locations/export/short')->assertNotFound();
});

// A refused request must not spend the token. Forgetting the claim before
// checking who it belongs to would let anyone with the link take the owner's
// one download away from them without ever receiving it.
it('leaves the token alive when the request is refused', function (): void {
    $owner = stagedExportUser();
    $this->actingAs($owner);

    /** @var StagedExportHandover $handover */
    $handover = app(StagedExportHandover::class);
    $path = stagedExportArchive('still-collectable');
    $token = $handover->stage($path, 'beatrax-export-2026-09-08.zip');

    $this->actingAs(stagedExportUser());
    $this->get('/help/data-locations/export/'.$token)->assertNotFound();

    $this->actingAs($owner);
    $response = $this->get('/help/data-locations/export/'.$token);

    $response->assertOk();
    expect($response->streamedContent())->toBe('still-collectable');
});

// An export the reader never collected is a copy of their whole database
// sitting in the container. Staging sweeps the directory, and it has to sweep
// all of it: the encrypted-backup path stages `.sqlite.enc`, and a plaintext
// `.sqlite` snapshot outlives a crashed encryption step.
it('sweeps every abandoned staging file, not only the archives it hands over', function (): void {
    $this->actingAs(stagedExportUser());

    $staging = UserDataPathService::appPath('tmp-backups');
    @mkdir($staging, 0700, true);

    $abandoned = [];

    foreach (['zip', 'sqlite.enc', 'sqlite'] as $extension) {
        $path = $staging.DIRECTORY_SEPARATOR.'beatrax-export-old-'.bin2hex(random_bytes(4)).'.'.$extension;
        file_put_contents($path, 'abandoned');
        touch($path, time() - 7200);
        $abandoned[$extension] = $path;
    }

    $fresh = stagedExportArchive();

    /** @var StagedExportHandover $handover */
    $handover = app(StagedExportHandover::class);
    $handover->stage($fresh, 'beatrax-export-2026-09-08.zip');

    foreach ($abandoned as $extension => $path) {
        expect(is_file($path))->toBeFalse("an hours-old .{$extension} survived the sweep");
    }

    expect(is_file($fresh))->toBeTrue('the sweep took a file staged moments ago');
});
