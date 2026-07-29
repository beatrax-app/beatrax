<?php

declare(strict_types=1);

use Modules\OpenBanking\Internal\Tls\LoopbackTlsCertificate;

/*
 * The loopback certificate refuses to report success when it could not write.
 *
 * Both halves are written before either is chmodded to 0600, so a write that
 * silently did nothing would leave the serve command handing a path to a file
 * that is missing, stale, or — worse — a previous key the user has already
 * verified by fingerprint.
 *
 * The guard is a `=== false` check on file_put_contents(), which only decides
 * anything because the call is suppressed: unsuppressed, Laravel's error
 * handler converts the E_WARNING into an ErrorException first.
 */
it('refuses to report a certificate it could not write', function (): void {
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-tls-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);

    // A directory where the certificate file belongs: the write refuses it.
    mkdir($dir.DIRECTORY_SEPARATOR.'cert.pem');

    expect(fn () => (new LoopbackTlsCertificate($dir))->ensure())
        ->toThrow(RuntimeException::class, 'Unable to write the loopback TLS certificate');

    @rmdir($dir.DIRECTORY_SEPARATOR.'cert.pem');
    foreach ((array) glob($dir.DIRECTORY_SEPARATOR.'*') as $f) {
        is_dir((string) $f) ? @rmdir((string) $f) : @unlink((string) $f);
    }
    @rmdir($dir);
});
