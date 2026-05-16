<?php

declare(strict_types=1);

use Modules\EmailScan\Public\Services\OAuthSecretsRepository;

beforeEach(function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('chmod semantics differ on Windows.');
    }
    $this->path = storage_path('app/secrets/email-oauth.json');
    $this->dir = dirname($this->path);
    // Tear down the secrets dir + file from any prior run so the
    // first-write path is exercised on each test.
    if (is_file($this->path)) {
        @unlink($this->path);
    }
    if (is_dir($this->dir)) {
        @rmdir($this->dir);
    }
});

afterEach(function (): void {
    if (is_file($this->path)) {
        @unlink($this->path);
    }
});

it('creates the parent directory with mode 0700 on first write', function (): void {
    expect(is_dir($this->dir))->toBeFalse();

    $repo = $this->app->make(OAuthSecretsRepository::class);
    $repo->saveProviderClient('gmail', 'id', 'sec', 'http://127.0.0.1/cb');

    expect(is_dir($this->dir))->toBeTrue();
    $mode = fileperms($this->dir) & 0777;
    expect(decoct($mode))->toBe('700');
});

it('subsequent writes on an existing dir do not error', function (): void {
    $repo = $this->app->make(OAuthSecretsRepository::class);
    $repo->saveProviderClient('gmail', 'id', 'sec', 'http://127.0.0.1/cb');
    // Second call must be a no-op as far as the dir is concerned.
    $repo->saveProviderClient('gmail', 'id2', 'sec2', 'http://127.0.0.1/cb');

    expect(is_dir($this->dir))->toBeTrue();
    $mode = fileperms($this->dir) & 0777;
    expect(decoct($mode))->toBe('700');
});
