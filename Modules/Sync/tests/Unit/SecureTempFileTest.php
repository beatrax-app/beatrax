<?php

declare(strict_types=1);

use Modules\Sync\Internal\Exceptions\SecretFileException;
use Modules\Sync\Internal\Identity\SecureTempFile;

// Plaintext Ed25519/X25519 secret keys were staged in sys_get_temp_dir() at
// default, world-readable permissions; this helper stages them at 0600.

function secureTempFilePath(string $suffix = ''): string
{
    $dir = sys_get_temp_dir().'/beatrax_secure_temp_file_test_'.bin2hex(random_bytes(8));
    mkdir($dir, 0700, true);

    return $dir.'/secret'.$suffix.'.tmp';
}

it('writes content and immediately restricts the file to 0600', function (): void {
    $path = secureTempFilePath();

    SecureTempFile::write($path, 'super-secret-key-material');

    expect(file_exists($path))->toBeTrue();
    expect(file_get_contents($path))->toBe('super-secret-key-material');
    expect(fileperms($path) & 0o777)->toBe(0o600, 'Staged secret file must be mode 0600.');

    @unlink($path);
});

it('locks an existing file (created at default permissions) down to 0600', function (): void {
    $path = secureTempFilePath('-existing');

    // Simulate BackupEncryptor::decrypt() producing a plaintext file with no
    // permission handling of its own (plain fopen(..., 'wb') at umask default).
    file_put_contents($path, 'decrypted-plaintext');
    chmod($path, 0644);
    expect(fileperms($path) & 0o777)->toBe(0o644, 'Precondition: file starts world-readable.');

    SecureTempFile::lockDown($path);

    expect(fileperms($path) & 0o777)->toBe(0o600, 'lockDown() must restrict the file to 0600.');
    expect(file_get_contents($path))->toBe('decrypted-plaintext');

    @unlink($path);
});

// The write is suppressed so its own `=== false` check decides. Unsuppressed,
// Laravel's handler converted the E_WARNING before the guard could run and this
// test accepted "either our exception or an ErrorException"; it can name the type
// it expects now.
it('throws and never leaves the file behind when write() cannot stage content', function (): void {
    $dir = sys_get_temp_dir().'/beatrax_secure_temp_file_test_missing_'.bin2hex(random_bytes(8));
    $path = $dir.'/secret.tmp';

    // Deliberately do NOT create $dir, so file_put_contents() fails.
    expect(fn () => SecureTempFile::write($path, 'secret'))
        ->toThrow(SecretFileException::class, 'Failed to stage secret material');

    expect(file_exists($path))->toBeFalse();
});

// A path that is a directory fails the write for a different reason than a
// missing parent, and is the shape an interrupted run leaves behind.
it('refuses to stage secret material over a directory', function (): void {
    $path = sys_get_temp_dir().'/beatrax_secure_temp_dir_'.bin2hex(random_bytes(8));
    mkdir($path);

    expect(fn () => SecureTempFile::write($path, 'secret'))
        ->toThrow(SecretFileException::class, 'Failed to stage secret material');

    @rmdir($path);
});
