<?php

declare(strict_types=1);

use Modules\Sync\Internal\Identity\SecureTempFile;

/*
 * SecureTempFileTest — the "stage plaintext secret material at 0600" helper
 * used at the device identity key-file's encrypt/decrypt boundary (security
 * fix: plaintext Ed25519/X25519 secret keys were staged in
 * sys_get_temp_dir() with default, world-readable permissions).
 */

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

it('throws and never leaves the file behind when write() cannot stage content', function (): void {
    $dir = sys_get_temp_dir().'/beatrax_secure_temp_file_test_missing_'.bin2hex(random_bytes(8));
    $path = $dir.'/secret.tmp';
    // Deliberately do NOT create $dir — file_put_contents() must fail.
    // Depending on error-handler configuration, a missing parent directory
    // surfaces as either our own RuntimeException (file_put_contents()
    // returns false) or an ErrorException from PHP's E_WARNING — either way
    // this must throw rather than silently continue, and never leave a file
    // behind. Caught manually (rather than Pest's toThrow(Throwable::class))
    // since Pest's toThrow() treats an interface class-string as a message
    // substring rather than a type check.
    $thrown = null;
    try {
        SecureTempFile::write($path, 'secret');
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect(file_exists($path))->toBeFalse();
});
