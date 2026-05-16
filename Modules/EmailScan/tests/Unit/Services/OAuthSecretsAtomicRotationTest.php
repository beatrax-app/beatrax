<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use Modules\EmailScan\Public\Services\SecretsWriteFailed;

beforeEach(function (): void {
    $this->path = storage_path('app/secrets/email-oauth.json');
    $this->tmp = $this->path.'.tmp';
    foreach ([$this->path, $this->tmp] as $p) {
        if (is_file($p)) {
            @unlink($p);
        }
    }
});

afterEach(function (): void {
    foreach ([$this->path, $this->tmp] as $p) {
        if (is_file($p)) {
            @unlink($p);
        }
    }
});

it('a failed mid-rotation rename leaves the prior file content intact', function (): void {
    $repo = $this->app->make(OAuthSecretsRepository::class);
    // Establish a known prior state on disk.
    $repo->saveProviderClient('gmail', 'original-id', 'original-secret', 'http://127.0.0.1/cb');
    $priorContents = (string) file_get_contents($this->path);

    // Subclass the repository with a performRename hook that always
    // returns false — simulating a mid-write failure where the temp
    // file was written but the atomic rename never landed.
    $failing = new class(new Filesystem) extends OAuthSecretsRepository
    {
        protected function performRename(string $tmp, string $final): bool
        {
            return false;
        }
    };

    expect(fn () => $failing->saveProviderClient('gmail', 'new-id', 'new-secret', 'http://127.0.0.1/cb'))
        ->toThrow(SecretsWriteFailed::class);

    // Prior file is intact, byte-for-byte.
    expect(is_file($this->path))->toBeTrue();
    expect((string) file_get_contents($this->path))->toBe($priorContents);

    // The .tmp orphan was cleaned up after the failure.
    expect(is_file($this->tmp))->toBeFalse();

    // Re-read via the repo — original credentials still resolvable.
    $loaded = $repo->loadProviderClient('gmail');
    expect($loaded)->not->toBeNull();
    expect($loaded['client_id'])->toBe('original-id');
});

it('SecretsWriteFailed message never carries the JSON payload', function (): void {
    $failing = new class(new Filesystem) extends OAuthSecretsRepository
    {
        protected function performRename(string $tmp, string $final): bool
        {
            return false;
        }
    };

    try {
        $failing->saveInboxRefreshToken(1, 'gmail', 'leak@test.local', 'super-secret-token', 'scope', null);
        expect(false)->toBeTrue('expected SecretsWriteFailed');
    } catch (SecretsWriteFailed $e) {
        // The exception message must not leak the credential payload.
        expect(str_contains($e->getMessage(), 'super-secret-token'))->toBeFalse();
        expect(str_contains($e->getMessage(), 'leak@test.local'))->toBeFalse();
    }
});
